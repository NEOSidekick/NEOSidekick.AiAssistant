<?php

namespace NEOSidekick\AiAssistant\Service;

use InvalidArgumentException;
use JsonException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Security\Exception;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Routing\Exception\NoSiteException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksApiException;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use PDO;
use Psr\Http\Client\ClientExceptionInterface;

class NodeService extends AbstractNodeService
{
    private const BASE_NODE_TYPE = 'NEOSidekick.AiAssistant:Mixin.AiPageBriefing';

    /**
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

    /**
     * @var \Neos\ContentRepository\Core\NodeType\NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @var SiteService
     */
    protected $siteService;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensions;

    /**
     * @var ApiFacade
     */
    protected $apiFacade;

    /**
     * @var NodeFindingService
     */
    protected $nodeFindingService;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    public function __construct(
        WorkspaceRepository $workspaceRepository,
        FindDocumentNodeDataFactory $findDocumentNodeDataFactory,
        \Neos\ContentRepository\Core\NodeType\NodeTypeManager $nodeTypeManager,
        SiteService $siteService,
        ApiFacade $apiFacade,
        NodeFindingService $nodeFindingService
    ) {
        $this->workspaceRepository = $workspaceRepository;
        $this->findDocumentNodeDataFactory = $findDocumentNodeDataFactory;
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $contentRepository->getNodeTypeManager() = $nodeTypeManager;
        $this->siteService = $siteService;
        $this->apiFacade = $apiFacade;
        $this->nodeFindingService = $nodeFindingService;
    }

    /**
     * @throws NodeException
     * @throws Exception
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws JsonException
     * @throws NeosException
     * @throws ClientExceptionInterface
     * @throws MissingActionNameException
     * @throws IllegalObjectTypeException
     * @throws GetMostRelevantInternalSeoLinksApiException
     */
    public function findImportantPages(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext, string $interfaceLanguage = 'en'): array
    {
        $currentRequestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        $hosts = [];
        if (isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            foreach ($this->contentDimensions[$this->languageDimensionName]['presets'] as $presetIdentifier => $preset) {
                if (sizeof($findDocumentNodesFilter->getLanguageDimensionFilter()) === 0 || in_array($presetIdentifier, $findDocumentNodesFilter->getLanguageDimensionFilter(), true)) {
                    $hosts[] = $currentRequestUri->getScheme() . '://' . $currentRequestUri->getHost() . '/' . $preset['uriSegment'];
                }
            }
        } else {
            $hosts = [$currentRequestUri->getScheme() . '://' . $currentRequestUri->getHost()];
        }
        $mostRelevantInternalSeoUris = $this->apiFacade->getMostRelevantInternalSeoUrisByHosts($hosts, $interfaceLanguage);

        $result = [];
        // TODO 9.0 migration: !! Node::getNodeData() - the new CR is not based around the concept of NodeData anymore. You need to rewrite your code here.
        foreach ($mostRelevantInternalSeoUris as $uri) {
            // Filter out URIs that do not match the current ControllerContext host
            if (!self::uriMatchesControllerContext((string)$uri, $controllerContext)) {
                continue;
            }

            $node = $this->nodeFindingService->tryToResolvePublicUriToNode((string)$uri, $findDocumentNodesFilter->getWorkspace());
            if ($node === null) {
                continue;
            }
            // Fallback URLs (e.g. /uk serving en content) are re-addressed to their ORIGIN
            // dimension: fallback pages are not editable rows - offering them would materialize
            // a variant on save (implicit copy-on-write), detaching the page from its fallback
            // chain. The underlying page stays in the list (deduped by its origin context path),
            // so editing it improves every URL it shines through to.
            if (DimensionFallbackDetector::isDimensionFallback($node)) {
                $node = $this->tryToResolveNodeInItsOwnDimension($node, $findDocumentNodesFilter->getWorkspace());
                if ($node === null) {
                    continue;
                }
            }
            if (!self::nodeMatchesPropertyFilter($node->getNodeData(), $findDocumentNodesFilter)) {
                continue;
            }
            if (!$this->nodeMatchesLanguageDimensionFilter($findDocumentNodesFilter, $node)) {
                continue;
            }
            if ($this->isNodeHidden($node)) {
                continue;
            }
            $result[\Neos\ContentRepository\Core\SharedModel\Node\NodeAddress::fromNode($node)->toJson()] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        // The result should be sorted by the length of the node path, so that the most specific nodes are first.
        ksort($result);

        return $result;
    }

    /**
     * Known limitation — hidden flag and workspace interaction:
     * The query applies `n.hidden = false` across all workspace rows BEFORE
     * {@see AbstractNodeService::reduceNodeVariantsByWorkspaces()} picks the
     * highest-priority variant. If a node is hidden only in the user workspace,
     * that row is excluded by SQL, and the live variant (hidden = false) may
     * still satisfy the query — causing the node to appear in results even
     * when the editor has hidden it in their workspace. This is accepted as a
     * low-priority edge case for now; revisit if user-reported.
     *
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     * @param ControllerContext       $controllerContext
     *
     * @return array
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws MissingActionNameException
     * @throws NeosException
     * @throws NoSiteException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Property\Exception
     */
    public function find(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext): array
    {
        $currentRequestHost = $controllerContext->getRequest()->getHttpRequest()->getUri()->getHost();
        $siteMatchingCurrentRequestHost = $this->siteService->getSiteByHostName($currentRequestHost);

        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.
        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $workspace = $contentRepository->findWorkspaceByName(\Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName::fromString($findDocumentNodesFilter->getWorkspace()));

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $workspaceChain = array_merge([$workspace], array_values($workspace->getBaseWorkspaces()));
        $queryBuilder = $this->createQueryBuilder($workspaceChain);
        $queryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $queryBuilder->andWhere('n.removed = :removed');
        $queryBuilder->andWhere('n.hidden = :hidden');
        $queryBuilder->andWhere($queryBuilder->expr()->orX(
            $queryBuilder->expr()->eq('n.path', ':currentSitePath'),
            $queryBuilder->expr()->like('n.path', ':currentSitePathWithWildcard')
        ));
        $currentSitePath = NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteMatchingCurrentRequestHost->getNodeName());
        $queryBuilder->setParameter('currentSitePath', $currentSitePath);
        $queryBuilder->setParameter('currentSitePathWithWildcard', $currentSitePath . '/%');
        $includeNodeTypes = $this->getNodeTypeFilter($findDocumentNodesFilter);
        $queryBuilder->setParameter('includeNodeTypes', $includeNodeTypes);
        $queryBuilder->setParameter('hidden', false, PDO::PARAM_BOOL);
        $queryBuilder->setParameter('removed', false, PDO::PARAM_BOOL);
        if (!empty($findDocumentNodesFilter->getLanguageDimensionFilter())) {
            $this->addDimensionJoinConstraintsToQueryBuilder(
                $queryBuilder,
                [$this->languageDimensionName => $findDocumentNodesFilter->getLanguageDimensionFilter()]
            );
        }
        $queryBuilder->addOrderBy('LENGTH(n.path)', 'ASC');
        $queryBuilder->addOrderBy('n.index', 'ASC');
        $queryBuilder->addOrderBy('n.dimensionsHash', 'DESC');
        $items = $queryBuilder->getQuery()->getResult();
        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);
        $itemsWithMatchingPropertyFilter = array_filter($itemsReducedByWorkspaceChain, static function (NodeData $nodeData) use ($findDocumentNodesFilter) {
            return self::nodeMatchesPropertyFilter($nodeData, $findDocumentNodesFilter);
        });

        $result = [];
        // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
        foreach ($itemsWithMatchingPropertyFilter as $nodeData) {
            // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
            $context = $this->createContentContext($findDocumentNodesFilter->getWorkspace(), $nodeData->getDimensionValues());
            $node = new \Neos\ContentRepository\Core\Projection\ContentGraph\Node($nodeData, $context);

            if ($this->isNodeHidden($node)) {
                continue;
            }

            $result[\Neos\ContentRepository\Core\SharedModel\Node\NodeAddress::fromNode($node)->toJson()] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        return $result;
    }

    /**
     * @param array<UpdateNodeProperties> $itemsToUpdate
     *
     * @return void
     */
    public function updatePropertiesOnNodes(array $itemsToUpdate): void
    {
        // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
        foreach ($itemsToUpdate as $updateItem) {
            /** @var array{nodePath: string, workspaceName: string, dimensions: array} $contextPathSegments */
            $contextPathSegments = NodePaths::explodeContextPath($updateItem->getNodeContextPath());
            // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
            $context = $this->createContentContext(
                $contextPathSegments['workspaceName'],
                $contextPathSegments['dimensions']
            );
            $node = $context->getNode($contextPathSegments['nodePath']);
            $this->assertNodeIsNotADimensionFallback($node, $updateItem->getNodeContextPath());
            foreach ($updateItem->getProperties() as $propertyName => $propertyValue) {
                $node->setProperty($propertyName, $propertyValue);
            }

            foreach ($updateItem->getImages() as $imageNodeContextPath => $imageNodeProperties) {
                if (empty($imageNodeProperties)) {
                    continue;
                }

                $imageNodeContextPathSegments = NodePaths::explodeContextPath($imageNodeContextPath);
                $imageNode = $context->getNode($imageNodeContextPathSegments['nodePath']);
                $this->assertNodeIsNotADimensionFallback($imageNode, $imageNodeContextPath);
                foreach ($imageNodeProperties as $propertyName => $propertyValue) {
                    $imageNode->setProperty($propertyName, $propertyValue);
                }
            }
        }
    }

    /**
     * @param NodeData                $nodeData
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return bool
     * @throws NodeException
     */
    protected static function nodeMatchesPropertyFilter(NodeData $nodeData, FindDocumentNodesFilter $findDocumentNodesFilter): bool
    {
        $focusKeywordValue = $nodeData->hasProperty('focusKeyword') ? $nodeData->getProperty('focusKeyword') : null;
        $nodeMatchesFocusKeywordPropertyFilter = match ($findDocumentNodesFilter->getFocusKeywordPropertyFilter()) {
            'none' => true,
            'only-empty-focus-keywords' => empty($focusKeywordValue),
            'only-existing-focus-keywords' => !empty($focusKeywordValue)
        };

        $titleOverride = $nodeData->hasProperty('titleOverride') ? $nodeData->getProperty('titleOverride') : null;
        $metaDescription = $nodeData->hasProperty('metaDescription') ? $nodeData->getProperty('metaDescription') : null;
        $nodeMatchesSeoPropertiesFilter = match ($findDocumentNodesFilter->getSeoPropertiesFilter()) {
            'none' => true,
            'only-empty-seo-titles-or-meta-descriptions' => empty($titleOverride) || empty($metaDescription),
            'only-empty-seo-titles' => empty($titleOverride),
            'only-empty-meta-descriptions' => empty($metaDescription),
            'only-existing-seo-titles' => !empty($titleOverride),
            'only-existing-meta-descriptions' => !empty($metaDescription),
        };

        return $nodeMatchesFocusKeywordPropertyFilter && $nodeMatchesSeoPropertiesFilter;
    }

    /**
     * This method returns an array of all possible node types
     * that are either a document node type OR match the filtered
     * document node type, but also have our mixin as a super-type.
     *
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return array<string>
     */
    protected function getNodeTypeFilter(FindDocumentNodesFilter $findDocumentNodesFilter): array
    {
        $documentNodeTypeFilter = $findDocumentNodesFilter->getNodeTypeFilter() ?? 'Neos.Neos:Document';
        $baseNodeTypeFilter = $findDocumentNodesFilter->getBaseNodeTypeFilter() ?? self::BASE_NODE_TYPE;
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $baseNodeTypeSubNodeTypes = $contentRepository->getNodeTypeManager()->getSubNodeTypes($baseNodeTypeFilter, false);
        $baseNodeTypeNameWithSubNodeTypeNames = [$baseNodeTypeFilter, ...array_keys($baseNodeTypeSubNodeTypes)];
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $documentSubNodeTypes = $contentRepository->getNodeTypeManager()->getSubNodeTypes($documentNodeTypeFilter, false);
        $documentNodeTypeNameWithSubNodeTypeNames = [$documentNodeTypeFilter, ...array_keys($documentSubNodeTypes)];
        $intersectNodeTypeNames = array_intersect(array_values($baseNodeTypeNameWithSubNodeTypeNames), array_values($documentNodeTypeNameWithSubNodeTypeNames));
        return array_values($intersectNodeTypeNames);
    }

    /**
     * Ensure a candidate public URI belongs to the same host as the current ControllerContext request.
     * The comparison is case-insensitive and ignores ports (compares host only).
     */
    protected static function uriMatchesControllerContext(string $uri, ControllerContext $controllerContext): bool
    {
        // parse_url returns host without scheme if missing; we expect absolute URIs from API
        $parsed = @parse_url($uri);
        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }
        $candidateHost = strtolower($parsed['host']);
        $currentHost = strtolower($controllerContext->getRequest()->getHttpRequest()->getUri()->getHost());
        return $candidateHost === $currentHost;
    }

    protected function nodeMatchesLanguageDimensionFilter(FindDocumentNodesFilter $findDocumentNodesFilter, \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): bool
    {
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return true;
        }
        // TODO 9.0 migration: Try to remove the toLegacyDimensionArray() call and make your codebase more typesafe.
        $nodeLanguageDimensionValues = $node->originDimensionSpacePoint->toLegacyDimensionArray()[$this->languageDimensionName];
        return sizeof(array_intersect($nodeLanguageDimensionValues, $findDocumentNodesFilter->getLanguageDimensionFilter())) > 0;
    }
}

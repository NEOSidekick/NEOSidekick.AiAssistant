<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\QueryBuilder;
use Generator;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\Workspace;
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
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Routing\Exception\NoSiteException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use Psr\Http\Client\ClientExceptionInterface;

class NodeService
{
    use CreateContentContextTrait;

    private const BASE_NODE_TYPE = 'NEOSidekick.AiAssistant:Mixin.AiPageBriefing';

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

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
     * @Flow\Inject
     * @var ApiFacade
     */
    protected $apiFacade;

    /**
     * @Flow\Inject
     * @var NodeFindingService
     */
    protected $nodeFindingService;

    /**
     * @throws NodeException
     * @throws Exception
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws \JsonException
     * @throws NeosException
     * @throws ClientExceptionInterface
     * @throws MissingActionNameException
     * @throws IllegalObjectTypeException
     */
    public function findImportantPages(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext): array
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
        $mostRelevantInternalSeoUris = $this->apiFacade->getMostRelevantInternalSeoUrisByHosts($hosts);

        $result = [];
        foreach ($mostRelevantInternalSeoUris as $uri) {
            $node = $this->nodeFindingService->tryToResolvePublicUriToNode((string)$uri, $findDocumentNodesFilter->getWorkspace());
            if ($node === null) {
                continue;
            }
            if ($this->isNodeHidden($node)) {
                continue;
            }
            if (!$this->nodeMatchesLanguageDimensionFilter($findDocumentNodesFilter, $node)) {
                continue;
            }
            $result[] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        return $result;
    }

    /**
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     * @param ControllerContext       $controllerContext
     *
     * @return array
     * @throws NoSiteException
     */
    public function find(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext): array
    {
        $currentRequestHost = $controllerContext->getRequest()->getHttpRequest()->getUri()->getHost();
        $siteMatchingCurrentRequestHost = $this->getSiteByHostName($currentRequestHost);
        $workspace = $this->workspaceRepository->findByIdentifier($findDocumentNodesFilter->getWorkspace());

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
        $queryBuilder->setParameter('currentSitePath', NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteMatchingCurrentRequestHost->getNodeName()));
        $queryBuilder->setParameter('currentSitePathWithWildcard', NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteMatchingCurrentRequestHost->getNodeName()) . '%');
        $queryBuilder->setParameter('includeNodeTypes', $this->getNodeTypeFilter($findDocumentNodesFilter));
        $queryBuilder->setParameter('hidden', false, \PDO::PARAM_BOOL);
        $queryBuilder->setParameter('removed', false, \PDO::PARAM_BOOL);
        if (!empty($findDocumentNodesFilter->getLanguageDimensionFilter())) {
            $this->addDimensionJoinConstraintsToQueryBuilder($queryBuilder,
                [$this->languageDimensionName => $findDocumentNodesFilter->getLanguageDimensionFilter()]);
        }
        $queryBuilder->addOrderBy('LENGTH(n.path)', 'ASC');
        $queryBuilder->addOrderBy('n.index', 'ASC');
        $queryBuilder->addOrderBy('n.dimensionsHash', 'DESC');
        $items = $queryBuilder->getQuery()->getResult();
        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);
        $itemsWithMatchingPropertyFilter = array_filter($itemsReducedByWorkspaceChain, function(NodeData $nodeData) use ($findDocumentNodesFilter) {
            return self::nodeMatchesPropertyFilter($nodeData, $findDocumentNodesFilter);
        });

        $result = [];
        foreach ($itemsWithMatchingPropertyFilter as $nodeData) {
            $context = $this->createContentContext($findDocumentNodesFilter->getWorkspace(), $nodeData->getDimensionValues());
            $node = new Node($nodeData, $context);

            if ($this->isNodeHidden($node)) {
                continue;
            }

            $result[] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
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
        foreach($itemsToUpdate as $updateItem) {
            /** @var array{nodePath: string, workspaceName: string, dimensions: array} $contextPathSegments */
            $contextPathSegments = NodePaths::explodeContextPath($updateItem->getNodeContextPath());
            $context = $this->createContentContext($contextPathSegments['workspaceName'],
                $contextPathSegments['dimensions']);
            $node = $context->getNode($contextPathSegments['nodePath']);
            foreach ($updateItem->getProperties() as $propertyName => $propertyValue) {
                $node->setProperty($propertyName, $propertyValue);
            }
        }
    }

    /**
     * @param NodeData                $nodeData
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return bool
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
     * @copyright Taken from and adapted: \Neos\Flow\Persistence\Doctrine\Repository::iterate()
     *
     * @param IterableResult $iterator
     *
     * @return Generator
     */
    protected function iterate(IterableResult $iterator): Generator
    {
        foreach ($iterator as $object) {
            $object = current($object);
            yield $object;
        }
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
        $mixinSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes(self::BASE_NODE_TYPE, false);
        $mixinNameWithSubNodeTypes = [self::BASE_NODE_TYPE, ...array_keys($mixinSubNodeTypes)];
        $documentSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes($documentNodeTypeFilter, false);
        $documentNameWithSubNodeTypes = [$documentNodeTypeFilter, ...array_keys($documentSubNodeTypes)];
        $intersectNodeTypes = array_intersect(array_values($mixinNameWithSubNodeTypes), array_values($documentNameWithSubNodeTypes));
        return array_values($intersectNodeTypes);
    }

    /**
     * @copyright Taken from and adapted: \Neos\ContentRepository\Domain\Repository\NodeDataRepository::createQueryBuilder()
     *
     * @param array $workspaces
     * @return QueryBuilder
     */
    protected function createQueryBuilder(array $workspaces): QueryBuilder
    {
        $workspacesNames = array_map(static function (Workspace $workspace) {
            return $workspace->getName();
        }, $workspaces);

        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder->select('n')
            ->from(NodeData::class, 'n')
            ->where('n.workspace IN (:workspaces)')
            ->setParameter('workspaces', $workspacesNames);

        return $queryBuilder;
    }

    /**
     * @copyright Taken from: Neos\ContentRepository\Domain\Repository\NodeDataRepository::addDimensionJoinConstraintsToQueryBuilder()
     *
     * If $dimensions is not empty, adds join constraints to the given $queryBuilder
     * limiting the query result to matching hits.
     *
     * @param QueryBuilder $queryBuilder
     * @param array $dimensions
     * @return void
     */
    protected function addDimensionJoinConstraintsToQueryBuilder(QueryBuilder $queryBuilder, array $dimensions)
    {
        $count = 0;
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            $dimensionAlias = 'd' . $count;
            $queryBuilder->andWhere(
                'EXISTS (SELECT ' . $dimensionAlias . ' FROM Neos\ContentRepository\Domain\Model\NodeDimension ' . $dimensionAlias . ' WHERE ' . $dimensionAlias . '.nodeData = n AND ' . $dimensionAlias . '.name = \'' . $dimensionName . '\' AND ' . $dimensionAlias . '.value IN (:' . $dimensionAlias . ')) ' .
                'OR NOT EXISTS (SELECT ' . $dimensionAlias . '_c FROM Neos\ContentRepository\Domain\Model\NodeDimension ' . $dimensionAlias . '_c WHERE ' . $dimensionAlias . '_c.nodeData = n AND ' . $dimensionAlias . '_c.name = \'' . $dimensionName . '\')'
            );
            $queryBuilder->setParameter($dimensionAlias, $dimensionValues);
            $count++;
        }
    }

    /**
     * @copyright Taken from and adapted: \Neos\ContentRepository\Domain\Repository\NodeDataRepository::reduceNodeVariantsByWorkspacesAndDimensions
     *
     * @param array<NodeData> $nodes
     * @param array<Workspace> $workspaces
     *
     * @return array<NodeData>
     */
    protected function reduceNodeVariantsByWorkspaces(array $nodes, array $workspaces): array
    {
        $reducedNodes = [];

        $minimalWorkspacePositionByIdentifier = [];

        $workspaceNames = array_map(
            function (Workspace $workspace) {
                return $workspace->getName();
            },
            $workspaces
        );
        foreach ($nodes as $node) {
            // Find the position of the workspace, a smaller value means more priority
            $workspacePosition = array_search($node->getWorkspace()->getName(), $workspaceNames);
            $identifier = $node->getIdentifier() . '-' . $node->getDimensionsHash();
            // Yes, it seems to work comparing arrays that way!
            if (!isset($minimalWorkspacePositionByIdentifier[$identifier]) || $workspacePosition < $minimalWorkspacePositionByIdentifier[$identifier]) {
                $reducedNodes[$identifier] = $node;
                $minimalWorkspacePositionByIdentifier[$identifier] = $workspacePosition;
            }
        }

        return $reducedNodes;
    }

    /**
     * @copyright Taken from: Neos\Neos\Routing\FrontendNodeRoutePartHandler::getSiteByHostname()
     *
     * Returns a site matching the given $hostName
     *
     * @param string $hostName
     *
     * @return Site
     * @throws NoSiteException
     */
    protected function getSiteByHostName(string $hostName): Site
    {
        $domain = $this->domainRepository->findOneByHost($hostName, true);
        if ($domain !== null) {
            return $domain->getSite();
        }
        try {
            $defaultSite = $this->siteRepository->findDefault();
            if ($defaultSite === null) {
                throw new NoSiteException('Failed to determine current site because no default site is configured', 1604929674);
            }
        } catch (NeosException $exception) {
            throw new NoSiteException(sprintf('Failed to determine current site because no domain is specified matching host of "%s" and no default site could be found: %s', $hostName, $exception->getMessage()), 1604860219, $exception);
        }
        return $defaultSite;
    }

    protected function isNodeHidden(Node $node): bool
    {
        try {
            $parentNode = $node->findParentNode();
        } catch (NodeException $e) {
            // This is thrown if no more parent node is found and that means our Node is not hidden
            return false;
        }
        if ($parentNode->isHidden()) {
            return true;
        }

        return $this->isNodeHidden($parentNode);
    }

    protected function nodeMatchesLanguageDimensionFilter(FindDocumentNodesFilter $findDocumentNodesFilter, Node $node): bool
    {
        $nodeLanguageDimensionValues = $node->getDimensions()[$this->languageDimensionName];
        return sizeof(array_intersect($nodeLanguageDimensionValues, $findDocumentNodesFilter->getLanguageDimensionFilter())) > 0;
    }
}

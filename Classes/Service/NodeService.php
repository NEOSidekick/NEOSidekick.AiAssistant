<?php

namespace NEOSidekick\AiAssistant\Service;

use InvalidArgumentException;
use JsonException;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Flow\Security\Exception;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\FrontendRouting\Options;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\Routing\Exception\NoSiteException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksApiException;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use Psr\Http\Client\ClientExceptionInterface;

class NodeService extends AbstractNodeService
{
    #[\Neos\Flow\Annotations\Inject]
    protected \NEOSidekick\AiAssistant\Service\ContentRepositoryProvider $contentRepositoryProvider;

    #[\Neos\Flow\Annotations\Inject]
    protected NodeUriBuilderFactory $nodeUriBuilderFactory;
    private const BASE_NODE_TYPE = 'NEOSidekick.AiAssistant:Mixin.AiPageBriefing';

    /**
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

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
     * @var ApiFacade
     */
    protected $apiFacade;

    /**
     * @var NodeFindingService
     */
    protected $nodeFindingService;

    public function __construct(
        FindDocumentNodeDataFactory $findDocumentNodeDataFactory,
        SiteService $siteService,
        ApiFacade $apiFacade,
        NodeFindingService $nodeFindingService
    ) {
        $this->findDocumentNodeDataFactory = $findDocumentNodeDataFactory;
        $this->siteService = $siteService;
        $this->apiFacade = $apiFacade;
        $this->nodeFindingService = $nodeFindingService;
    }

    /**
     * @throws Exception
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Http\Exception
     * @throws JsonException
     * @throws NeosException
     * @throws ClientExceptionInterface
     * @throws MissingActionNameException
     * @throws NoSiteException
     * @throws GetMostRelevantInternalSeoLinksApiException
     */
    public function findImportantPages(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext, string $interfaceLanguage = 'en'): array
    {
        $currentRequestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        $contentRepository = $this->contentRepositoryProvider->getContentRepository();
        $languageDimension = isset($this->languageDimensionName)
            ? $contentRepository->getContentDimensionSource()->getDimension(new ContentDimensionId($this->languageDimensionName))
            : null;

        $hosts = [];
        if ($languageDimension !== null) {
            // NOTE (Neos 9 migration decision): the entry URL per language is the router-generated
            // homepage URI of the site node in that language's dimension space point. Hand-building
            // "<host>/<uriSegment>" URLs (Neos 8 behavior) breaks for the site-default language,
            // whose homepage has an EMPTY uri path in Neos 9 (e.g. /en would 404 while / works) —
            // the NEOSidekick API then wrongly reports the site as not publicly accessible.
            $site = $this->siteService->getSiteByHostName($currentRequestUri->getHost());
            $liveContentGraph = $contentRepository->getContentGraph(WorkspaceName::forLive());
            $sitesRootAggregate = $liveContentGraph->findRootNodeAggregateByType(NodeTypeName::fromString('Neos.Neos:Sites'));
            $siteNodeAggregate = $sitesRootAggregate === null ? null : $liveContentGraph->findChildNodeAggregateByName(
                $sitesRootAggregate->nodeAggregateId,
                $site->getNodeName()->toNodeName()
            );
            $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($controllerContext->getRequest());
            foreach ($languageDimension->values as $dimensionValue) {
                if (sizeof($findDocumentNodesFilter->getLanguageDimensionFilter()) === 0 || in_array($dimensionValue->value, $findDocumentNodesFilter->getLanguageDimensionFilter(), true)) {
                    if ($siteNodeAggregate === null) {
                        continue;
                    }
                    try {
                        $homepageUri = $nodeUriBuilder->uriFor(NodeAddress::create(
                            $contentRepository->id,
                            WorkspaceName::forLive(),
                            DimensionSpacePoint::fromArray([$this->languageDimensionName => $dimensionValue->value]),
                            $siteNodeAggregate->nodeAggregateId
                        ), Options::createForceAbsolute());
                    } catch (NoMatchingRouteException) {
                        // no homepage variant in this language — nothing to crawl there
                        continue;
                    }
                    $hosts[] = rtrim((string)$homepageUri, '/');
                }
            }
        } else {
            $hosts = [$currentRequestUri->getScheme() . '://' . $currentRequestUri->getHost()];
        }
        $mostRelevantInternalSeoUris = $this->apiFacade->getMostRelevantInternalSeoUrisByHosts($hosts, $interfaceLanguage);

        $result = [];
        foreach ($mostRelevantInternalSeoUris as $uri) {
            // Filter out URIs that do not match the current ControllerContext host
            if (!self::uriMatchesControllerContext((string)$uri, $controllerContext)) {
                continue;
            }

            $node = $this->nodeFindingService->tryToResolvePublicUriToNode((string)$uri, $findDocumentNodesFilter->getWorkspace());
            if ($node === null) {
                continue;
            }
            // Fallback URLs (e.g. /uk serving en_US content) are re-addressed to their ORIGIN
            // dimension: fallback pages are not editable rows — offering them would either write
            // to the origin or materialize a variant as a save side effect, both of which break
            // the fallback concept. The underlying page stays in the list (deduped by its origin
            // address), so editing it improves every URL it shines through to.
            $originDimensionSpacePoint = $node->originDimensionSpacePoint->toDimensionSpacePoint();
            if (!$node->dimensionSpacePoint->equals($originDimensionSpacePoint)) {
                $node = $contentRepository->getContentGraph($node->workspaceName)
                    ->getSubgraph($originDimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved())
                    ->findNodeById($node->aggregateId);
                if ($node === null) {
                    continue;
                }
            }
            if (!self::nodeMatchesPropertyFilter($node, $findDocumentNodesFilter)) {
                continue;
            }
            if (!$this->nodeMatchesLanguageDimensionFilter($findDocumentNodesFilter, $node)) {
                continue;
            }
            if ($this->isNodeHidden($node)) {
                continue;
            }
            $result[NodeAddress::fromNode($node)->toJson()] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        ksort($result);

        return $result;
    }

    /**
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     * @param ControllerContext       $controllerContext
     *
     * @return array
     * @throws Exception
     * @throws MissingActionNameException
     * @throws NeosException
     * @throws NoSiteException
     * @throws \Neos\Flow\Http\Exception
     * @throws \Neos\Flow\Property\Exception
     */
    public function find(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext): array
    {
        $currentRequestHost = $controllerContext->getRequest()->getHttpRequest()->getUri()->getHost();
        $siteMatchingCurrentRequestHost = $this->siteService->getSiteByHostName($currentRequestHost);

        $contentRepository = $this->contentRepositoryProvider->getContentRepository();
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::fromString($findDocumentNodesFilter->getWorkspace()));

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $includeNodeTypes = $this->getNodeTypeFilter($findDocumentNodesFilter);
        // The subgraphs of the requested workspace already contain the nodes inherited from its base workspaces
        // and NodeVisibility::excludeDisabledAndRemoved() excludes disabled nodes, replacing the old workspace-chain
        // reduction and the "hidden"/"removed" query constraints.
        // NOTE (Neos 9 migration decision): the old query ordered by path length and sorting index; findDescendantNodes
        // returns nodes in the graph's natural (tree) order per dimension space point, so the result order can differ.
        $nodesByVariant = $this->findDocumentNodesInWorkspace(
            $contentRepository,
            $workspace->workspaceName,
            $includeNodeTypes,
            $siteMatchingCurrentRequestHost->getNodeName()->value
        );

        $result = [];
        foreach ($nodesByVariant as $node) {
            if (!self::nodeMatchesPropertyFilter($node, $findDocumentNodesFilter)) {
                continue;
            }
            if (
                !empty($findDocumentNodesFilter->getLanguageDimensionFilter())
                && !$this->nodeMatchesLanguageDimensionFilter($findDocumentNodesFilter, $node)
            ) {
                continue;
            }

            $result[NodeAddress::fromNode($node)->toJson()] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        return $result;
    }

    /**
     * The nodeContextPath of each item carries the NodeAddress JSON produced by
     * {@see FindDocumentNodeDataFactory::createFromNode()}; the frontend round-trips it as an opaque id.
     *
     * @param array<UpdateNodeProperties> $itemsToUpdate
     *
     * @return void
     */
    public function updatePropertiesOnNodes(array $itemsToUpdate): void
    {
        foreach ($itemsToUpdate as $updateItem) {
            $this->updatePropertiesOnNode($updateItem->getNodeContextPath(), $updateItem->getProperties());

            foreach ($updateItem->getImages() as $imageNodeContextPath => $imageNodeProperties) {
                if (empty($imageNodeProperties)) {
                    continue;
                }

                $this->updatePropertiesOnNode($imageNodeContextPath, $imageNodeProperties);
            }
        }
    }

    /**
     * @param string $nodeAddressJson NodeAddress JSON as produced by NodeAddress::toJson()
     * @param array<string, mixed> $properties
     */
    private function updatePropertiesOnNode(string $nodeAddressJson, array $properties): void
    {
        if ($properties === []) {
            return;
        }
        $nodeAddress = NodeAddress::fromJsonString($nodeAddressJson);
        $contentRepository = $this->contentRepositoryRegistry->get($nodeAddress->contentRepositoryId);
        $subgraph = $contentRepository->getContentGraph($nodeAddress->workspaceName)
            ->getSubgraph($nodeAddress->dimensionSpacePoint, NodeVisibility::excludeRemoved());
        $node = $subgraph->findNodeById($nodeAddress->aggregateId);
        if ($node === null) {
            throw new InvalidArgumentException(sprintf('Node "%s" was not found and cannot be updated.', $nodeAddressJson), 1713440899887);
        }
        // Writes to fallback addresses are rejected: writing to the origin would silently change
        // the source language's content, and materializing a variant as a save side effect would
        // permanently detach the page from its fallback chain (variant creation must stay an
        // explicit editor decision in the Neos UI). find()/findImportantPages() only emit
        // origin-addressed rows, so this is a defense against stale or handcrafted addresses.
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($nodeAddress->dimensionSpacePoint);
        if (!$node->originDimensionSpacePoint->equals($targetOrigin)) {
            throw new InvalidArgumentException(sprintf(
                'Node "%s" is a fallback of dimension %s in the addressed dimension %s and cannot be edited here. Create the variant in the Neos UI first.',
                $nodeAddress->aggregateId->value,
                $node->originDimensionSpacePoint->toJson(),
                $nodeAddress->dimensionSpacePoint->toJson()
            ), 1752060000001);
        }
        $contentRepository->handle(SetNodeProperties::create(
            $nodeAddress->workspaceName,
            $nodeAddress->aggregateId,
            $targetOrigin,
            PropertyValuesToWrite::fromArray($properties)
        ));
    }

    /**
     * @param Node                    $node
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return bool
     */
    protected static function nodeMatchesPropertyFilter(Node $node, FindDocumentNodesFilter $findDocumentNodesFilter): bool
    {
        $focusKeywordValue = $node->hasProperty('focusKeyword') ? $node->getProperty('focusKeyword') : null;
        $nodeMatchesFocusKeywordPropertyFilter = match ($findDocumentNodesFilter->getFocusKeywordPropertyFilter()) {
            'none' => true,
            'only-empty-focus-keywords' => empty($focusKeywordValue),
            'only-existing-focus-keywords' => !empty($focusKeywordValue)
        };

        $titleOverride = $node->hasProperty('titleOverride') ? $node->getProperty('titleOverride') : null;
        $metaDescription = $node->hasProperty('metaDescription') ? $node->getProperty('metaDescription') : null;
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
        $contentRepository = $this->contentRepositoryProvider->getContentRepository();
        $nodeTypeManager = $contentRepository->getNodeTypeManager();

        $baseNodeTypeSubNodeTypes = $nodeTypeManager->getSubNodeTypes($baseNodeTypeFilter, false);
        $baseNodeTypeNameWithSubNodeTypeNames = [$baseNodeTypeFilter, ...array_keys($baseNodeTypeSubNodeTypes)];
        $documentSubNodeTypes = $nodeTypeManager->getSubNodeTypes($documentNodeTypeFilter, false);
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

    protected function nodeMatchesLanguageDimensionFilter(FindDocumentNodesFilter $findDocumentNodesFilter, Node $node): bool
    {
        if (!isset($this->languageDimensionName)) {
            return true;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        if ($contentRepository->getContentDimensionSource()->getDimension($languageDimensionId) === null) {
            return true;
        }
        // Match against the dimension the node is served in (the subgraph/address dimension):
        // fallback pages (e.g. /uk serving en_US content) count as their served language, like
        // the old CR context dimensions did — not as their origin.
        $nodeLanguageDimensionValue = $node->dimensionSpacePoint->getCoordinate($languageDimensionId);
        return $nodeLanguageDimensionValue !== null
            && in_array($nodeLanguageDimensionValue, $findDocumentNodesFilter->getLanguageDimensionFilter(), true);
    }
}

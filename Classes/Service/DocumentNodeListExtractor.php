<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;
use Psr\Log\LoggerInterface;

/**
 * Service to extract document node list from Neos.
 *
 * Traverses the document tree and extracts minimal data needed
 * for LLM agents to discover and navigate pages.
 *
 * @Flow\Scope("singleton")
 */
class DocumentNodeListExtractor
{
    use PropertyExtractionTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';
    private const SITE_TYPE = 'Neos.Neos:Site';
    private const SITES_ROOT_TYPE = 'Neos.Neos:Sites';

    /**
     * Properties to include in the document list response.
     * Configurable via NEOSidekick.AiAssistant.documentNodeList.includedProperties
     *
     * @Flow\InjectConfiguration(path="documentNodeList.includedProperties")
     * @var array|null
     */
    protected ?array $includedProperties = null;

    #[\Neos\Flow\Annotations\Inject]
    protected \NEOSidekick\AiAssistant\Service\ContentRepositoryProvider $contentRepositoryProvider;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Get the list of properties to include.
     *
     * @return array
     */
    protected function getIncludedProperties(): array
    {
        return $this->includedProperties ?? ['title', 'metaDescription', 'uriPathSegment'];
    }

    /**
     * Extract all document nodes for a site.
     *
     * @param string $workspace Workspace name (default: 'live')
     * @param array $dimensions Content dimensions
     * @param string|null $siteNodeName Site node name (null = the site whose Domain record matches the request host, else Neos's default site)
     * @param string $nodeTypeFilter Filter by NodeType
     * @param int $depth Maximum traversal depth (-1 = unlimited)
     * @param string|null $requestHost Host of the incoming request, used when no site node name is given
     * @return array
     */
    public function extract(
        string $workspace = 'live',
        array $dimensions = [],
        ?string $siteNodeName = null,
        string $nodeTypeFilter = self::DOCUMENT_TYPE,
        int $depth = -1,
        ?string $requestHost = null
    ): array {
        $contentRepository = $this->contentRepositoryProvider->getContentRepository();
        $workspaceObject = $contentRepository->findWorkspaceByName(WorkspaceName::fromString($workspace));
        if ($workspaceObject === null) {
            throw new \InvalidArgumentException(
                sprintf('Workspace "%s" not found', $workspace),
                1735660099
            );
        }

        $dimensionSpacePoint = $this->resolveDimensionSpacePoint($contentRepository, $dimensions);
        // Backend-like reading (Neos 8 parity with invisibleContentShown): disabled documents must
        // stay in the result so the isHidden field carries a signal; only removed nodes are excluded.
        $subgraph = $contentRepository->getContentGraph($workspaceObject->workspaceName)
            ->getSubgraph($dimensionSpacePoint, NodeVisibility::excludeRemoved());

        $sitesRootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString(self::SITES_ROOT_TYPE));
        $availableSiteNodes = $sitesRootNode === null ? [] : $this->findAvailableSiteNodes($subgraph, $sitesRootNode);
        $siteNode = $sitesRootNode === null
            ? null
            : $this->resolveSiteNode($subgraph, $sitesRootNode, $siteNodeName, $requestHost, $availableSiteNodes);

        if ($siteNode === null) {
            throw new \InvalidArgumentException('No site found', 1735660100);
        }

        // One recursive CTE query over the document tree instead of one findChildNodes() query per
        // node. Neos 8 parity: documents are discovered through document chains only, and `depth`
        // counts document levels — non-document wrappers neither consume the depth budget nor
        // inflate the reported depth. Nodes at the depth boundary still need their child documents
        // for childDocumentCount, so the query goes one level deeper than requested.
        // PHP_INT_MAX is effectively unlimited (and "+ 1" would overflow to float)
        $subtree = $subgraph->findSubtree($siteNode->aggregateId, FindSubtreeFilter::create(
            nodeTypes: self::DOCUMENT_TYPE,
            maximumLevels: ($depth >= 0 && $depth < PHP_INT_MAX) ? $depth + 1 : null
        ));

        $documents = [];
        if ($subtree !== null) {
            $sitePath = $this->tryRetrieveNodePath($subgraph, $subtree->node);
            $this->collectDocuments($contentRepository, $subtree, $nodeTypeFilter, $depth, $sitePath, $documents);
        }

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'site' => [
                'name' => $siteNode->name?->value,
                'nodeType' => $siteNode->nodeTypeName->value,
                'identifier' => $siteNode->aggregateId->value,
            ],
            'availableSites' => $this->describeSiteNodes($availableSiteNodes),
            'documents' => $documents,
            'documentCount' => count($documents),
        ];
    }

    /**
     * Resolve the site node to query: an explicitly requested site, else the site whose active
     * Domain record matches the request host (Neos's suffix matching, site state ignored), else
     * Neos's default site (`Neos.Neos.defaultSiteNodeName`, else the first online site); only
     * when Neos has no default either, the first site below the sites root, logged.
     *
     * @param array<int, Node> $availableSiteNodes
     * @throws \InvalidArgumentException If a site node name is given that is not a site node name or does not exist
     */
    private function resolveSiteNode(
        ContentSubgraphInterface $subgraph,
        Node $sitesRootNode,
        ?string $siteNodeName,
        ?string $requestHost,
        array $availableSiteNodes
    ): ?Node {
        if ($siteNodeName !== null) {
            $normalizedSiteNodeName = strtolower($siteNodeName);
            $siteNode = preg_match(NodeName::PATTERN, $normalizedSiteNodeName) === 1
                ? $subgraph->findNodeByPath(NodeName::fromString($normalizedSiteNodeName), $sitesRootNode->aggregateId)
                : null;
            if ($siteNode === null) {
                throw new \InvalidArgumentException(sprintf(
                    'No site "%s" found. Available sites: %s',
                    $siteNodeName,
                    implode(', ', array_map(static fn(Node $node): string => (string)$node->name?->value, $availableSiteNodes))
                ), 1735660100);
            }

            return $siteNode;
        }

        $hostSiteNode = $this->resolveSiteNodeByHost($subgraph, $sitesRootNode, $requestHost);
        if ($hostSiteNode !== null) {
            return $hostSiteNode;
        }

        $this->logger->info(sprintf(
            'NEOSidekick document list: no site matches the request host "%s", falling back to the first site of the installation.',
            $requestHost ?? ''
        ), ['requestHost' => $requestHost]);

        return $availableSiteNodes[0] ?? null;
    }

    /**
     * The node of the site whose active Domain record matches the given host, else Neos's default
     * site (`Neos.Neos.defaultSiteNodeName`, else the first online site). Null when there is no
     * host at all or when Neos has no default site either.
     */
    private function resolveSiteNodeByHost(ContentSubgraphInterface $subgraph, Node $sitesRootNode, ?string $requestHost): ?Node
    {
        if ($requestHost === null) {
            return null;
        }

        $site = $this->domainRepository->findOneByHost($requestHost, true)?->getSite()
            ?? $this->siteRepository->findDefault();
        if ($site === null) {
            return null;
        }

        return $subgraph->findNodeByPath(NodeName::fromString($site->getNodeName()->value), $sitesRootNode->aggregateId);
    }

    /**
     * All site nodes of this installation, in tree order.
     *
     * @return array<int, Node>
     */
    private function findAvailableSiteNodes(ContentSubgraphInterface $subgraph, Node $sitesRootNode): array
    {
        $siteNodes = $subgraph->findChildNodes($sitesRootNode->aggregateId, FindChildNodesFilter::create(nodeTypes: self::SITE_TYPE));
        if ($siteNodes->count() === 0) {
            // for sites with custom NodeTypes
            $siteNodes = $subgraph->findChildNodes($sitesRootNode->aggregateId, FindChildNodesFilter::create());
        }

        return iterator_to_array($siteNodes, false);
    }

    /**
     * @param array<int, Node> $siteNodes
     * @return array<int, array{nodeName: string, name: string}>
     */
    private function describeSiteNodes(array $siteNodes): array
    {
        return array_map(static fn(Node $node): array => [
            'nodeName' => (string)$node->name?->value,
            'name' => (string)($node->getProperty('title') ?? $node->name?->value),
        ], $siteNodes);
    }

    /**
     * Collect matching documents from the pre-fetched document subtree.
     *
     * Paths are built incrementally from the subtree structure (parent path + node name) instead
     * of one retrieveNodePath() query per document; a nameless ancestor makes the whole branch's
     * paths unresolvable, matching retrieveNodePath()'s behavior.
     */
    private function collectDocuments(
        ContentRepository $contentRepository,
        Subtree $subtree,
        string $nodeTypeFilter,
        int $maxDepth,
        ?string $path,
        array &$documents
    ): void {
        // The subtree was queried one level deeper than requested (for childDocumentCount)
        if ($maxDepth >= 0 && $subtree->level > $maxDepth) {
            return;
        }

        $node = $subtree->node;
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)?->isOfType($nodeTypeFilter) === true) {
            $documents[] = $this->extractDocumentData($node, $subtree->level, $path, count($subtree->children));
        }

        foreach ($subtree->children as $childSubtree) {
            $childName = $childSubtree->node->name?->value;
            $childPath = ($path !== null && $childName !== null) ? $path . '/' . $childName : null;
            $this->collectDocuments($contentRepository, $childSubtree, $nodeTypeFilter, $maxDepth, $childPath, $documents);
        }
    }

    /**
     * Extract data from a single document node.
     */
    private function extractDocumentData(Node $node, int $depth, ?string $path, int $childDocumentCount): array
    {
        return [
            'identifier' => $node->aggregateId->value,
            'nodeType' => $node->nodeTypeName->value,
            // NOTE (Neos 9 migration decision): node paths now use the absolute path format
            // "/<Neos.Neos:Sites>/site/..." instead of the legacy "/sites/site/..." format.
            'path' => $path ?? '',
            'depth' => $depth,
            'title' => $node->getProperty('title') ?? $node->name?->value,
            'uriPath' => $node->getProperty('uriPathSegment') ?? '',
            'properties' => $this->extractSelectedProperties($node),
            'childDocumentCount' => $childDocumentCount,
            // Neos 8 parity: the node's OWN hidden state, not one inherited from an ancestor
            'isHidden' => $node->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()),
            // "hidden in index" is a regular node property in Neos 9 (see Neos.Neos:Mixin.Document)
            'isHiddenInMenu' => (bool)$node->getProperty('hiddenInMenu'),
        ];
    }

    /**
     * Retrieves the absolute node path as string, or null when the path cannot be built
     * (e.g. because an ancestor node has no name — node names are optional in Neos 9).
     */
    private function tryRetrieveNodePath(ContentSubgraphInterface $subgraph, Node $node): ?string
    {
        try {
            return $subgraph->retrieveNodePath($node->aggregateId)->serializeToString();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Maps the legacy dimensions array (which allowed a list of fallback values per dimension) to a
     * dimension space point; when no dimensions are given, the most general dimension space point is used.
     *
     * @param array<string, mixed> $dimensions
     */
    private function resolveDimensionSpacePoint(ContentRepository $contentRepository, array $dimensions): DimensionSpacePoint
    {
        $coordinates = [];
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            // NOTE (Neos 9 migration decision): legacy dimension arrays carried fallback values; only the primary value is used now
            $coordinates[$dimensionName] = is_array($dimensionValues) ? (string)reset($dimensionValues) : (string)$dimensionValues;
        }
        if ($coordinates !== []) {
            return DimensionSpacePoint::fromArray($coordinates);
        }
        $rootGeneralizations = $contentRepository->getVariationGraph()->getRootGeneralizations();
        return $rootGeneralizations !== [] ? reset($rootGeneralizations) : DimensionSpacePoint::createWithoutDimensions();
    }
}

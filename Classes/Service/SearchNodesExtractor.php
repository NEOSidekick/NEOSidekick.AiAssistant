<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;

/**
 * Service to search nodes in the Neos content repository.
 *
 * Performs grep-like search across all node properties and supports
 * direct lookup by node identifier, returning matching nodes with their
 * context information for LLM agents.
 *
 * @Flow\Scope("singleton")
 */
class SearchNodesExtractor
{
    use PropertyExtractionTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';
    private const SITES_ROOT_TYPE = 'Neos.Neos:Sites';

    /**
     * Properties to include in the search results response.
     * Configurable via NEOSidekick.AiAssistant.searchNodes.includedProperties
     *
     * @Flow\InjectConfiguration(path="searchNodes.includedProperties")
     * @var array|null
     */
    protected ?array $includedProperties = null;

    #[\Neos\Flow\Annotations\Inject]
    protected \NEOSidekick\AiAssistant\Service\ContentRepositoryProvider $contentRepositoryProvider;

    /**
     * Get the list of properties to include in results.
     *
     * @return array
     */
    protected function getIncludedProperties(): array
    {
        // Default to common properties if not configured
        return $this->includedProperties ?? ['title', 'text', 'headline', 'metaDescription'];
    }

    /**
     * Search nodes by property values or identifier.
     *
     * Performs a case-insensitive search across all node properties and
     * supports direct node identifier lookup, returning matching nodes
     * with their context information.
     *
     * @param string $query The search term or candidate node identifier
     * @param string $workspace Workspace name (default: 'live')
     * @param array $dimensions Content dimensions
     * @param string|null $nodeTypeFilter Filter by NodeType
     * @param string|null $pathStartingPoint Limit search to nodes under this path
     * @return array Search results
     */
    public function search(
        string $query,
        string $workspace = 'live',
        array $dimensions = [],
        ?string $nodeTypeFilter = null,
        ?string $pathStartingPoint = null
    ): array {
        $contentRepository = $this->contentRepositoryProvider->getContentRepository();
        $workspaceObject = $contentRepository->findWorkspaceByName(WorkspaceName::fromString($workspace));
        if ($workspaceObject === null) {
            throw new \InvalidArgumentException(
                sprintf('Workspace "%s" not found', $workspace),
                1735661000
            );
        }

        $dimensionSpacePoint = $this->resolveDimensionSpacePoint($contentRepository, $dimensions);
        $subgraph = $contentRepository->getContentGraph($workspaceObject->workspaceName)
            ->getSubgraph($dimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved());

        // If no nodeTypeFilter is given, search all content types (not just documents)
        $effectiveNodeTypeFilter = $nodeTypeFilter ?? 'Neos.Neos:Node';

        $entryNode = $this->resolveEntryNode($subgraph, $pathStartingPoint);

        $resultsByKey = [];
        if ($entryNode !== null) {
            // The searchTerm filter replaces the old NodeDataRepository::findByProperties() fulltext search
            $matchingNodes = $subgraph->findDescendantNodes(
                $entryNode->aggregateId,
                FindDescendantNodesFilter::create(nodeTypes: $effectiveNodeTypeFilter, searchTerm: $query)
            );
            foreach ($matchingNodes as $node) {
                $resultKey = $node->aggregateId->value . '|' . ($this->tryRetrieveNodePath($subgraph, $node) ?? '');
                $resultsByKey[$resultKey] = $this->extractNodeData($subgraph, $node);
            }
        }

        // Also support direct identifier lookups in addition to property search.
        $nodeByIdentifier = $this->resolveNodeByIdentifier(
            $contentRepository,
            $subgraph,
            $query,
            $effectiveNodeTypeFilter,
            $pathStartingPoint
        );
        if ($nodeByIdentifier !== null) {
            $resultKey = $nodeByIdentifier->aggregateId->value . '|' . ($this->tryRetrieveNodePath($subgraph, $nodeByIdentifier) ?? '');
            $resultsByKey[$resultKey] = $this->extractNodeData($subgraph, $nodeByIdentifier);
        }

        $results = array_values($resultsByKey);

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'query' => $query,
            'nodeTypeFilter' => $nodeTypeFilter,
            'pathStartingPoint' => $pathStartingPoint,
            'results' => $results,
            'resultCount' => count($results),
        ];
    }

    /**
     * Resolve a query string as a node identifier and apply existing filters.
     *
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph The subgraph to search in
     * @param string $identifier Candidate node identifier
     * @param string|null $nodeTypeFilter Effective node type filter to apply
     * @param string|null $pathStartingPoint Optional path prefix filter
     * @return Node|null
     */
    private function resolveNodeByIdentifier(
        ContentRepository $contentRepository,
        ContentSubgraphInterface $subgraph,
        string $identifier,
        ?string $nodeTypeFilter,
        ?string $pathStartingPoint
    ): ?Node {
        try {
            $node = $subgraph->findNodeById(NodeAggregateId::fromString($identifier));
        } catch (\Throwable) {
            return null;
        }

        if ($node === null) {
            return null;
        }

        if (
            $nodeTypeFilter !== null
            && !$this->nodeMatchesNodeTypeFilter($contentRepository, $node, $nodeTypeFilter)
        ) {
            return null;
        }

        if (
            $pathStartingPoint !== null
            && !str_starts_with((string)$this->tryRetrieveNodePath($subgraph, $node), $this->normalizePathStartingPoint($pathStartingPoint))
        ) {
            return null;
        }

        return $node;
    }

    /**
     * Evaluates the node type filter string with the same semantics as findDescendantNodes()
     * ({@see NodeTypeCriteria}): deny rules win, an empty allow-list allows everything —
     * a plain isOfType() call would misread multi-type filters like "A,B,!C".
     */
    private function nodeMatchesNodeTypeFilter(ContentRepository $contentRepository, Node $node, string $nodeTypeFilter): bool
    {
        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return false;
        }
        $criteria = NodeTypeCriteria::fromFilterString($nodeTypeFilter);
        foreach ($criteria->explicitlyDisallowedNodeTypeNames as $disallowedNodeTypeName) {
            if ($nodeType->isOfType($disallowedNodeTypeName)) {
                return false;
            }
        }
        if ($criteria->explicitlyAllowedNodeTypeNames->isEmpty()) {
            return true;
        }
        foreach ($criteria->explicitlyAllowedNodeTypeNames as $allowedNodeTypeName) {
            if ($nodeType->isOfType($allowedNodeTypeName)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolves the node to start the search from: the given path starting point, or the sites root node.
     *
     * Accepts both the Neos 9 absolute path format ("/<Neos.Neos:Sites>/site/...") and the
     * legacy path format ("/sites/site/...").
     */
    private function resolveEntryNode(ContentSubgraphInterface $subgraph, ?string $pathStartingPoint): ?Node
    {
        if ($pathStartingPoint === null || $pathStartingPoint === '') {
            return $subgraph->findRootNodeByType(NodeTypeName::fromString(self::SITES_ROOT_TYPE));
        }

        $absoluteNodePath = AbsoluteNodePath::tryFromString($this->normalizePathStartingPoint($pathStartingPoint));
        if ($absoluteNodePath === null) {
            return null;
        }

        return $subgraph->findNodeByAbsolutePath($absoluteNodePath);
    }

    /**
     * Converts a legacy "/sites/..." path into the Neos 9 absolute path format; other values pass through.
     * Public because {@see SearchNodesApiController} applies the same normalization to its path filter.
     */
    public function normalizePathStartingPoint(string $path): string
    {
        if (str_starts_with($path, '/sites')) {
            $relativePath = ltrim(substr($path, strlen('/sites')), '/');
            return '/<' . self::SITES_ROOT_TYPE . '>' . ($relativePath !== '' ? '/' . $relativePath : '');
        }
        return $path;
    }

    /**
     * Extract data from a single node.
     *
     * @param ContentSubgraphInterface $subgraph The subgraph the node was found in
     * @param Node $node The node to extract data from
     * @return array Extracted node data
     */
    private function extractNodeData(ContentSubgraphInterface $subgraph, Node $node): array
    {
        // NOTE (Neos 9 migration decision): node paths now use the absolute path format
        // "/<Neos.Neos:Sites>/site/..." instead of the legacy "/sites/site/..." format.
        $path = $this->tryRetrieveNodePath($subgraph, $node);
        $depth = $path !== null ? AbsoluteNodePath::fromString($path)->getDepth() : 0;

        // Find parent document for content nodes
        $parentDocument = $subgraph->findClosestNode($node->aggregateId, FindClosestNodeFilter::create(nodeTypes: self::DOCUMENT_TYPE));

        $data = [
            'identifier' => $node->aggregateId->value,
            'nodeType' => $node->nodeTypeName->value,
            'path' => $path ?? '',
            'depth' => $depth,
            'properties' => $this->extractSelectedProperties($node),
            'isHidden' => $node->tags->contain(NeosSubtreeTag::disabled()),
        ];

        // Add parent document info if available (for content nodes)
        if ($parentDocument !== null && !$parentDocument->aggregateId->equals($node->aggregateId)) {
            $data['parentDocumentIdentifier'] = $parentDocument->aggregateId->value;
            $data['parentDocumentPath'] = $this->tryRetrieveNodePath($subgraph, $parentDocument) ?? '';
            $data['parentDocumentTitle'] = $parentDocument->getProperty('title') ?? $parentDocument->name?->value;
        }

        return $data;
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

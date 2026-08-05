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
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;

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
     * @param string|null $siteNodeName Site node name (null = first site)
     * @param string $nodeTypeFilter Filter by NodeType
     * @param int $depth Maximum traversal depth (-1 = unlimited)
     * @return array
     */
    public function extract(
        string $workspace = 'live',
        array $dimensions = [],
        ?string $siteNodeName = null,
        string $nodeTypeFilter = self::DOCUMENT_TYPE,
        int $depth = -1
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

        $siteNode = $this->resolveSiteNode($contentRepository, $subgraph, $siteNodeName);

        if ($siteNode === null) {
            throw new \InvalidArgumentException('No site found', 1735660100);
        }

        // One recursive CTE query over the document tree instead of one findChildNodes() query per
        // node. Neos 8 parity: documents are discovered through document chains only, and `depth`
        // counts document levels — non-document wrappers neither consume the depth budget nor
        // inflate the reported depth. Nodes at the depth boundary still need their child documents
        // for childDocumentCount, so the query goes one level deeper than requested.
        $subtree = $subgraph->findSubtree($siteNode->aggregateId, FindSubtreeFilter::create(
            nodeTypes: self::DOCUMENT_TYPE,
            maximumLevels: $depth >= 0 ? $depth + 1 : null
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
            'documents' => $documents,
            'documentCount' => count($documents),
        ];
    }

    /**
     * Resolve the site node to query.
     */
    private function resolveSiteNode(ContentRepository $contentRepository, ContentSubgraphInterface $subgraph, ?string $siteNodeName): ?Node
    {
        $sitesRootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString(self::SITES_ROOT_TYPE));
        if ($sitesRootNode === null) {
            return null;
        }

        // If a specific site is requested, resolve it directly
        if ($siteNodeName !== null) {
            return $subgraph->findNodeByPath(NodeName::fromString($siteNodeName), $sitesRootNode->aggregateId);
        }

        // Try to get the first site below the sites root node
        $childNodes = $subgraph->findChildNodes($sitesRootNode->aggregateId, FindChildNodesFilter::create(nodeTypes: self::SITE_TYPE));
        if ($childNodes->count() > 0) {
            return $childNodes->first();
        }

        // Fallback: get any child node of the sites root (for sites with custom NodeTypes)
        return $subgraph->findChildNodes($sitesRootNode->aggregateId, FindChildNodesFilter::create())->first();
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

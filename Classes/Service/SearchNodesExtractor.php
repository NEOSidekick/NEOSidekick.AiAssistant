<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;

/**
 * Service to search nodes in the Neos content repository.
 *
 * Performs grep-like search across all node properties and returns
 * matching nodes with their context information for LLM agents.
 *
 * @Flow\Scope("singleton")
 */
class SearchNodesExtractor
{
    use CreateContentContextTrait;
    use PropertyExtractionTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var NodeFactory
     */
    protected $nodeFactory;

    /**
     * Properties to include in the search results response.
     * Configurable via NEOSidekick.AiAssistant.searchNodes.includedProperties
     *
     * @Flow\InjectConfiguration(path="searchNodes.includedProperties")
     * @var array|null
     */
    protected ?array $includedProperties = null;

    /**
     * Return the configured property names to include in extracted node results.
     *
     * If no properties are configured, returns the default list: ['title', 'text', 'headline', 'metaDescription'].
     *
     * @return string[] List of property names to include.
     */
    protected function getIncludedProperties(): array
    {
        // Default to common properties if not configured
        return $this->includedProperties ?? ['title', 'text', 'headline', 'metaDescription'];
    }

    /**
     * Find nodes whose properties match a query and return a structured result set with context.
     *
     * Searches nodes using the given workspace, dimensions, optional node type filter and optional path
     * restriction, and returns metadata plus extracted per-node data for each matching node.
     *
     * @param string $query The search term to match against node properties.
     * @param string $workspace Workspace name to run the search in (e.g. "live").
     * @param array $dimensions Content dimensions to resolve nodes in (dimension name => value).
     * @param string|null $nodeTypeFilter Optional NodeType identifier to limit the search.
     * @param string|null $pathStartingPoint Optional path to restrict the search to nodes beneath.
     * @return array An associative array with keys: `generatedAt` (ISO 8601 timestamp), `workspace`, `dimensions`, `query`, `nodeTypeFilter`, `pathStartingPoint`, `results` (array of extracted node data), and `resultCount`.
     */
    public function search(
        string $query,
        string $workspace = 'live',
        array $dimensions = [],
        ?string $nodeTypeFilter = null,
        ?string $pathStartingPoint = null
    ): array {
        // Resolve workspace object
        $workspaceObject = $this->workspaceRepository->findByIdentifier($workspace);
        if ($workspaceObject === null) {
            throw new \InvalidArgumentException(
                sprintf('Workspace "%s" not found', $workspace),
                1735661000
            );
        }

        // Use NodeDataRepository::findByProperties for search
        // If no nodeTypeFilter is given, search all content types (not just documents)
        $effectiveNodeTypeFilter = $nodeTypeFilter ?? 'Neos.Neos:Node';

        $nodeDataResults = $this->nodeDataRepository->findByProperties(
            $query,
            $effectiveNodeTypeFilter,
            $workspaceObject,
            $dimensions,
            $pathStartingPoint
        );

        // Create content context for resolving nodes
        $context = $this->createContentContext($workspace, $dimensions);

        // Extract result data from NodeData objects
        $results = [];
        foreach ($nodeDataResults as $nodeData) {
            /** @var NodeData $nodeData */
            $node = $this->nodeFactory->createFromNodeData($nodeData, $context);

            // Skip nodes that couldn't be resolved in context
            if ($node === null) {
                continue;
            }

            $results[] = $this->extractNodeData($node);
        }

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
         * Builds a structured array of metadata and selected properties for the given node.
         *
         * The result always includes:
         * - `identifier`: node identifier
         * - `nodeType`: node type name
         * - `path`: node path
         * - `depth`: depth of the node path
         * - `properties`: array of selected node properties
         * - `isHidden`: whether the node is hidden
         *
         * If a closest document node exists and is not the node itself, the result also includes:
         * - `parentDocumentIdentifier`, `parentDocumentPath`, `parentDocumentTitle`
         *
         * @param NodeInterface $node The node to extract data from.
         * @return array Associative array with node metadata, selected properties, and optional parent document details.
         */
    private function extractNodeData(NodeInterface $node): array
    {
        $path = $node->getPath();
        $depth = NodePaths::getPathDepth($path);

        // Find parent document for content nodes
        $parentDocument = $this->findClosestDocumentNode($node);

        $data = [
            'identifier' => $node->getIdentifier(),
            'nodeType' => $node->getNodeType()->getName(),
            'path' => $path,
            'depth' => $depth,
            'properties' => $this->extractSelectedProperties($node),
            'isHidden' => $node->isHidden(),
        ];

        // Add parent document info if available (for content nodes)
        if ($parentDocument !== null && $parentDocument !== $node) {
            $data['parentDocumentIdentifier'] = $parentDocument->getIdentifier();
            $data['parentDocumentPath'] = $parentDocument->getPath();
            $data['parentDocumentTitle'] = $parentDocument->getProperty('title') ?? $parentDocument->getName();
        }

        return $data;
    }

    /**
     * Find the closest document node (parent page) for a content node.
     *
     * @param NodeInterface $node The node to find the parent document for
     * @return NodeInterface|null The closest document node or null
     */
    private function findClosestDocumentNode(NodeInterface $node): ?NodeInterface
    {
        // If this node is already a document, return it
        if ($node->getNodeType()->isOfType(self::DOCUMENT_TYPE)) {
            return $node;
        }

        // Walk up the tree to find the parent document
        $current = $node->getParent();
        while ($current !== null) {
            if ($current->getNodeType()->isOfType(self::DOCUMENT_TYPE)) {
                return $current;
            }
            $current = $current->getParent();
        }

        return null;
    }

}
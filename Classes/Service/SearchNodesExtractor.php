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
use Neos\Neos\Domain\Service\ContentContext;

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
     * Search nodes by property values.
     *
     * Performs a case-insensitive search across all node properties,
     * returning matching nodes with their context information.
     *
     * @param string $query The search term
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

            $results[] = $this->extractNodeData($node, $context);
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
     * Extract data from a single node.
     *
     * @param NodeInterface $node The node to extract data from
     * @param ContentContext $context The content context
     * @return array Extracted node data
     */
    private function extractNodeData(NodeInterface $node, ContentContext $context): array
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

    /**
     * Extract only the configured properties from a node.
     *
     * @param NodeInterface $node The node to extract properties from
     * @return array Extracted properties
     */
    private function extractSelectedProperties(NodeInterface $node): array
    {
        $properties = [];
        foreach ($this->getIncludedProperties() as $propertyName) {
            $value = $node->getProperty($propertyName);
            if ($value !== null) {
                $properties[$propertyName] = $value;
            }
        }
        return $properties;
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Neos\Controller\CreateContentContextTrait;

/**
 * Service to extract raw node tree data from Neos.
 *
 * This service traverses a node tree starting from a given node and extracts
 * all relevant data including properties and children. The output uses the
 * unified children model:
 * - Nodes that ARE ContentCollections use '_self' slot
 * - Nodes with named childNodes have those as named slots
 * - Nodes with no children have an empty children object
 *
 * @Flow\Scope("singleton")
 */
class NodeTreeExtractor
{
    /**
     * The NodeType name for ContentCollection which indicates the node itself is a collection.
     */
    private const CONTENT_COLLECTION_TYPE = 'Neos.Neos:ContentCollection';

    /**
     * Default maximum depth for tree extraction to prevent stack overflow.
     */
    private const DEFAULT_MAX_DEPTH = 50;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Extract the node tree starting from a given node.
     *
     * @param string $nodeId The node identifier (UUID) to start from
     * @param string $workspace The workspace name
     * @param array $dimensions The dimension values (e.g., ['language' => ['de']])
     * @param int|null $maxDepth Maximum depth for tree extraction (null uses default, prevents stack overflow)
     * @return array{generatedAt: string, workspace: string, dimensions: array, rootNode: array}
     * @throws \InvalidArgumentException When node is not found
     */
    public function extract(string $nodeId, string $workspace, array $dimensions, ?int $maxDepth = null): array
    {
        // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
        $context = $this->createContentContext($workspace, $dimensions);
        $node = $context->getNodeByIdentifier($nodeId);

        if ($node === null) {
            throw new \InvalidArgumentException(
                sprintf('Node with identifier "%s" not found in workspace "%s"', $nodeId, $workspace),
                1735660000
            );
        }

        $effectiveMaxDepth = $maxDepth ?? self::DEFAULT_MAX_DEPTH;

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'rootNode' => $this->extractNode($node, 0, $effectiveMaxDepth),
        ];
    }

    /**
     * Extract data for a single node including its children.
     *
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node The node to extract
     * @param int $currentDepth Current recursion depth
     * @param int $maxDepth Maximum allowed depth
     * @return array{id: string, nodeType: string, properties: array, children: array}
     */
    private function extractNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, int $currentDepth, int $maxDepth): array
    {
        // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.
        return [
            'id' => $node->aggregateId->value,
            'nodeType' => $node->nodeTypeName->value,
            'properties' => $this->extractProperties($node),
            'children' => $this->extractChildren($node, $currentDepth, $maxDepth),
        ];
    }

    /**
     * Extract properties from a node, filtering internal properties.
     *
     * Excludes properties starting with underscore (except _hidden),
     * and serializes assets appropriately.
     *
     * @return array<string, mixed>
     */
    private function extractProperties(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): array
    {
        $properties = $node->properties;
        $result = [];

        foreach ($properties as $propertyName => $propertyValue) {
            // Filter internal properties (same logic as TypeScriptGenerator)
            if (!$this->shouldIncludeProperty($propertyName)) {
                continue;
            }

            // Serialize the property value
            $result[$propertyName] = $this->serializePropertyValue($propertyValue);
        }

        return $result;
    }

    /**
     * Determine if a property should be included in the output.
     *
     * Internal properties (starting with underscore) are excluded,
     * except for _hidden which is user-editable.
     */
    private function shouldIncludeProperty(string $propertyName): bool
    {
        if (str_starts_with($propertyName, '_')) {
            return $propertyName === '_hidden';
        }
        return true;
    }

    /**
     * Serialize a property value to a JSON-compatible format.
     *
     * Handles special types like images and assets.
     *
     * @param mixed $value The property value
     * @return mixed The serialized value
     */
    private function serializePropertyValue(mixed $value): mixed
    {
        // Handle assets (includes Image, which extends Asset)
        if ($value instanceof Asset) {
            return [
                'identifier' => $value->getIdentifier(),
                'filename' => $value->getResource()?->getFilename() ?? '',
                'mediaType' => $value->getResource()?->getMediaType() ?? '',
            ];
        }

        // Handle arrays (may contain nested assets)
        if (is_array($value)) {
            return array_map(fn($item) => $this->serializePropertyValue($item), $value);
        }

        // Handle DateTime objects
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        // Handle objects that cannot be serialized
        if (is_object($value)) {
            // Try to get a string representation
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            // Return null for objects that can't be serialized
            return null;
        }

        // Scalars and null pass through as-is
        return $value;
    }

    /**
     * Extract children from a node using the unified children model.
     *
     * Returns an object with named slots:
     * - '_self' slot if the node IS a ContentCollection
     * - Named slots for each configured childNode
     *
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node The node to extract children from
     * @param int $currentDepth Current recursion depth
     * @param int $maxDepth Maximum allowed depth
     * @return array<string, array{allowedTypes: array<string>, nodes: array}>
     */
    private function extractChildren(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, int $currentDepth, int $maxDepth): array
    {
        // Prevent stack overflow on deeply nested trees
        if ($currentDepth >= $maxDepth) {
            return [];
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        $children = [];

        // Check if node IS a ContentCollection (gets _self slot)
        if ($nodeType->isOfType(self::CONTENT_COLLECTION_TYPE)) {
            $allowedTypes = $this->extractAllowedTypesFromConstraints(
                $nodeType->getConfiguration('constraints.nodeTypes') ?? []
            );
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

            // Get all direct child nodes (the content inside this collection)
            // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.
            $childNodes = iterator_to_array($subgraph->findChildNodes($node->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::create()));
            $serializedChildren = [];
            foreach ($childNodes as $childNode) {
                // Skip auto-created child nodes (those are handled via named slots)
                if ($this->isAutoCreatedChildNode($childNode, $nodeType)) {
                    continue;
                }
                $serializedChildren[] = $this->extractNode($childNode, $currentDepth + 1, $maxDepth);
            }

            $children['_self'] = [
                'allowedTypes' => $allowedTypes,
                'nodes' => $serializedChildren,
            ];
        }

        // Handle named childNodes (auto-created slots like 'main', 'fields', etc.)
        // These are typically ContentCollection nodes with their own UUIDs
        $childNodesConfig = $nodeType->getConfiguration('childNodes') ?? [];
        foreach ($childNodesConfig as $childNodeName => $childNodeConfig) {
            $childNode = $node->getNode($childNodeName);
            if ($childNode === null) {
                continue;
            }

            $allowedTypes = $this->resolveChildNodeAllowedTypes($childNodeConfig);
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($childNode);

            // Get the content inside this named slot
            // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.
            $slotChildren = iterator_to_array($subgraph->findChildNodes($childNode->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::create()));
            $serializedSlotChildren = [];
            foreach ($slotChildren as $slotChild) {
                $serializedSlotChildren[] = $this->extractNode($slotChild, $currentDepth + 1, $maxDepth);
            }

            // Include the ContentCollection node's id and nodeType
            // so it can be represented as a proper node in JSX
            // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.
            $children[$childNodeName] = [
                'id' => $childNode->aggregateId->value,
                'nodeType' => $childNode->nodeTypeName->value,
                'allowedTypes' => $allowedTypes,
                'nodes' => $serializedSlotChildren,
            ];
        }

        return $children;
    }

    /**
     * Check if a child node is an auto-created named childNode.
     *
     * Auto-created childNodes are configured in the NodeType's childNodes
     * config and should be handled as named slots, not as _self content.
     */
    private function isAutoCreatedChildNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $childNode, \Neos\ContentRepository\Core\NodeType\NodeType $parentNodeType): bool
    {
        $childNodesConfig = $parentNodeType->getConfiguration('childNodes') ?? [];
        return isset($childNodesConfig[$childNode->nodeName]);
    }

    /**
     * Resolve allowed types from a childNode configuration.
     *
     * @return array<string>
     */
    private function resolveChildNodeAllowedTypes(array $childNodeConfig): array
    {
        $constraints = $childNodeConfig['constraints']['nodeTypes'] ?? [];
        return $this->extractAllowedTypesFromConstraints($constraints);
    }

    /**
     * Extract allowed types from a constraints configuration.
     *
     * @return array<string>
     */
    private function extractAllowedTypesFromConstraints(array $constraints): array
    {
        $allowedTypes = [];

        foreach ($constraints as $nodeTypeName => $isAllowed) {
            if ($nodeTypeName === '*') {
                continue;
            }
            if ($isAllowed === true) {
                $allowedTypes[] = $nodeTypeName;
            }
        }

        return $allowedTypes;
    }
}

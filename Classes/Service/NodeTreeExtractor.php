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
    use CreateContentContextTrait;

    /**
     * The NodeType name for ContentCollection which indicates the node itself is a collection.
     */
    private const CONTENT_COLLECTION_TYPE = 'Neos.Neos:ContentCollection';

    /**
     * Default maximum depth for tree extraction to prevent stack overflow.
     */
    private const DEFAULT_MAX_DEPTH = 50;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
         * Extract the node tree starting from a given node.
         *
         * @param string $nodeId The node identifier (UUID) to start from.
         * @param string $workspace The workspace name.
         * @param array $dimensions Dimension values (e.g., ['language' => ['de']]).
         * @param int|null $maxDepth Maximum depth for tree extraction; when null the class default is used.
         * @return array{generatedAt: string, rootNode: array} Payload containing an ATOM timestamp (`generatedAt`) and the serialized root node (`rootNode`).
         * @throws \InvalidArgumentException When the node identified by `$nodeId` cannot be found in the specified workspace.
         */
    public function extract(string $nodeId, string $workspace, array $dimensions, ?int $maxDepth = null): array
    {
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
            'rootNode' => $this->extractNode($node, 0, $effectiveMaxDepth),
        ];
    }

    /**
     * Extract data for a single node including its children.
     *
     * @param NodeInterface $node The node to extract
     * @param int $currentDepth Current recursion depth
     * @param int $maxDepth Maximum allowed depth
     * @return array{id: string, nodeType: string, properties: array, children: array}
     */
    private function extractNode(NodeInterface $node, int $currentDepth, int $maxDepth): array
    {
        return [
            'id' => $node->getIdentifier(),
            'nodeType' => $node->getNodeType()->getName(),
            'properties' => $this->extractProperties($node),
            'children' => $this->extractChildren($node, $currentDepth, $maxDepth),
        ];
    }

    /**
     * Extract properties from a node, filtering internal properties and serializing values.
     *
     * Filters out properties whose names start with '_' except for '_hidden', and converts
     * property values into JSON-friendly representations (e.g., assets, DateTime, arrays,
     * objects with __toString, and scalars).
     *
     * @return array<string, mixed> Map of property name to serialized property value.
     */
    private function extractProperties(NodeInterface $node): array
    {
        $properties = $node->getProperties();
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
     * Decides whether a node property should be included in the serialized output.
     *
     * Internal properties whose names start with `_` are excluded, except for `_hidden`
     * which is allowed.
     *
     * @param string $propertyName The property name to evaluate.
     * @return bool `true` if the property should be included, `false` otherwise.
     */
    private function shouldIncludeProperty(string $propertyName): bool
    {
        if (str_starts_with($propertyName, '_')) {
            return $propertyName === '_hidden';
        }
        return true;
    }

    /**
         * Convert a node property value into a JSON-friendly representation.
         *
         * Assets (including Images) are represented as an associative array with `identifier`, `filename`, and `mediaType`.
         * Arrays are recursively converted element-by-element. Date/time values are formatted using the ATOM timestamp.
         * Objects implementing `__toString()` are converted to their string form; other objects become `null`.
         * Scalars and `null` are returned unchanged.
         *
         * @param mixed $value The property value to serialize.
         * @return mixed The serialized representation suitable for JSON encoding.
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
         * Produce a unified children structure for a node, including a `_self` slot for content collections and named slots for configured childNodes.
         *
         * If `currentDepth` is greater than or equal to `maxDepth`, an empty array is returned to stop recursion.
         * For a ContentCollection node a `_self` slot is produced with:
         * - `allowedTypes`: allowed node type names derived from the node type constraints
         * - `nodes`: serialized child nodes (excluding auto-created named children)
         * For each configured named childNode a slot is produced with:
         * - `id`: the named child node's identifier
         * - `nodeType`: the named child node's type name
         * - `allowedTypes`: allowed node type names from the child node configuration
         * - `nodes`: serialized child nodes contained in that named slot
         *
         * @param NodeInterface $node The node to extract children from.
         * @param int $currentDepth Current recursion depth used to enforce the `maxDepth` guard.
         * @param int $maxDepth Maximum allowed recursion depth; when reached children extraction stops.
         * @return array<string, array{allowedTypes: array<string>, nodes: array}> Mapping of slot name to slot payload. Named slots additionally include `id` and `nodeType`.
    private function extractChildren(NodeInterface $node, int $currentDepth, int $maxDepth): array
    {
        // Prevent stack overflow on deeply nested trees
        if ($currentDepth >= $maxDepth) {
            return [];
        }

        $nodeType = $node->getNodeType();
        $children = [];

        // Check if node IS a ContentCollection (gets _self slot)
        if ($nodeType->isOfType(self::CONTENT_COLLECTION_TYPE)) {
            $allowedTypes = $this->extractAllowedTypesFromConstraints(
                $nodeType->getConfiguration('constraints.nodeTypes') ?? []
            );

            // Get all direct child nodes (the content inside this collection)
            $childNodes = $node->getChildNodes();
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

            // Get the content inside this named slot
            $slotChildren = $childNode->getChildNodes();
            $serializedSlotChildren = [];
            foreach ($slotChildren as $slotChild) {
                $serializedSlotChildren[] = $this->extractNode($slotChild, $currentDepth + 1, $maxDepth);
            }

            // Include the ContentCollection node's id and nodeType
            // so it can be represented as a proper node in JSX
            $children[$childNodeName] = [
                'id' => $childNode->getIdentifier(),
                'nodeType' => $childNode->getNodeType()->getName(),
                'allowedTypes' => $allowedTypes,
                'nodes' => $serializedSlotChildren,
            ];
        }

        return $children;
    }

    /**
     * Determine whether the given child node is defined as an auto-created named child in the parent's node type configuration.
     *
     * Auto-created named child nodes are entries present in the parent node type's `childNodes` configuration and should be treated as named slots rather than regular _self content.
     *
     * @param NodeInterface $childNode The child node to check.
     * @param NodeType $parentNodeType The parent node type whose `childNodes` configuration will be inspected.
     * @return bool `true` if the child node is configured as an auto-created named child, `false` otherwise.
     */
    private function isAutoCreatedChildNode(NodeInterface $childNode, NodeType $parentNodeType): bool
    {
        $childNodesConfig = $parentNodeType->getConfiguration('childNodes') ?? [];
        return isset($childNodesConfig[$childNode->getName()]);
    }

    /**
     * Determine which node types are permitted for a configured child node slot.
     *
     * @param array<string,mixed> $childNodeConfig Child node configuration array (expected to contain a `constraints.nodeTypes` mapping).
     * @return array<string> List of allowed node type names extracted from `constraints.nodeTypes` (wildcard entries are ignored).
     */
    private function resolveChildNodeAllowedTypes(array $childNodeConfig): array
    {
        $constraints = $childNodeConfig['constraints']['nodeTypes'] ?? [];
        return $this->extractAllowedTypesFromConstraints($constraints);
    }

    /**
         * Collect node type names that are marked allowed in a constraints mapping.
         *
         * Skips wildcard ('*') entries.
         *
         * @param array<string,bool> $constraints Mapping of node type name to a boolean indicating allowance.
         * @return array<string> Node type names whose constraint value is `true`, excluding the wildcard entry.
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
<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * DTO representing information about a created node.
 *
 * Used to return details about nodes created during a createNode patch operation,
 * including auto-created child nodes (fixed child nodes) and nodes from NodeTemplates.
 *
 * @Flow\Proxy(false)
 */
final class CreatedNodeInfo implements JsonSerializable
{
    private string $nodeId;

    private string $nodeType;

    private string $nodeName;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    private int $depth;

    /**
     * Create a CreatedNodeInfo containing the node's identifier, type, name, properties and relative depth.
     *
     * @param string $nodeId The node identifier (UUID).
     * @param string $nodeType The full NodeType name.
     * @param string $nodeName The node name (path segment).
     * @param array<string,mixed> $properties The node's properties.
     * @param int $depth Depth relative to the main created node (0 = main node).
     */
    public function __construct(
        string $nodeId,
        string $nodeType,
        string $nodeName,
        array $properties,
        int $depth = 0
    ) {
        $this->nodeId = $nodeId;
        $this->nodeType = $nodeType;
        $this->nodeName = $nodeName;
        $this->properties = $properties;
        $this->depth = $depth;
    }

    /**
     * Retrieve the UUID of the created node.
     *
     * @return string The node's UUID.
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Get the full NodeType name of the created node.
     *
     * @return string The full NodeType name.
     */
    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    /**
     * Provides the node's name (path segment).
     *
     * @return string The node's name (path segment).
     */
    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    /**
     * Retrieve the node's properties as an associative array keyed by property name.
     *
     * @return array<string, mixed> Associative array of node properties keyed by property name.
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Depth of this node relative to the main created node.
     *
     * @return int The depth relative to the main created node (0 = main node).
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * Provide a JSON-serializable representation of the created node.
     *
     * @return array{nodeId: string, nodeType: string, nodeName: string, properties: array<string, mixed>, depth: int} Associative array with keys `nodeId`, `nodeType`, `nodeName`, `properties` and `depth`.
     */
    public function jsonSerialize(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'nodeType' => $this->nodeType,
            'nodeName' => $this->nodeName,
            'properties' => $this->properties,
            'depth' => $this->depth,
        ];
    }
}
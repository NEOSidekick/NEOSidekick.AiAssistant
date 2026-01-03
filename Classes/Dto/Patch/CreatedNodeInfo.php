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
     * @param string $nodeId The node identifier (UUID)
     * @param string $nodeType The full NodeType name
     * @param string $nodeName The node name (path segment)
     * @param array<string, mixed> $properties The node's properties
     * @param int $depth The depth relative to the main created node (0 = main node)
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

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * @return array{nodeId: string, nodeType: string, nodeName: string, properties: array<string, mixed>, depth: int}
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

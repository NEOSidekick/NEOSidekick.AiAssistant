<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for creating a new node.
 *
 * @Flow\Proxy(false)
 */
final class CreateNodePatch extends AbstractPatch
{
    protected string $operation = 'createNode';

    private string $parentNodeId;

    private string $nodeType;

    /**
     * Position: 'into', 'before', or 'after'
     */
    private string $position;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    /**
     * @param string $parentNodeId
     * @param string $nodeType
     * @param string $position
     * @param array<string, mixed> $properties
     */
    public function __construct(
        string $parentNodeId,
        string $nodeType,
        string $position = 'into',
        array $properties = []
    ) {
        $this->parentNodeId = $parentNodeId;
        $this->nodeType = $nodeType;
        $this->position = $position;
        $this->properties = $properties;
    }

    public function getParentNodeId(): string
    {
        return $this->parentNodeId;
    }

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param array{operation: string, parentNodeId: string, nodeType: string, position?: string, properties?: array<string, mixed>} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['parentNodeId'])) {
            throw new \InvalidArgumentException('CreateNodePatch requires "parentNodeId"');
        }
        if (!isset($data['nodeType'])) {
            throw new \InvalidArgumentException('CreateNodePatch requires "nodeType"');
        }

        return new self(
            $data['parentNodeId'],
            $data['nodeType'],
            $data['position'] ?? 'into',
            $data['properties'] ?? []
        );
    }

    /**
     * @return array{operation: string, parentNodeId: string, nodeType: string, position: string, properties: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'parentNodeId' => $this->parentNodeId,
            'nodeType' => $this->nodeType,
            'position' => $this->position,
            'properties' => $this->properties,
        ];
    }
}

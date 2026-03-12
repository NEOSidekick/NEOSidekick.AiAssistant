<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for updating node properties.
 *
 * @Flow\Proxy(false)
 */
final class UpdateNodePatch extends AbstractPatch
{
    protected string $operation = 'updateNode';

    private string $nodeId;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    /**
     * @param string $nodeId
     * @param array<string, mixed> $properties
     */
    public function __construct(string $nodeId, array $properties)
    {
        $this->nodeId = $nodeId;
        $this->properties = $properties;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param array{operation: string, nodeId: string, properties: array<string, mixed>} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId'])) {
            throw new \InvalidArgumentException('UpdateNodePatch requires "nodeId"');
        }
        if (!isset($data['properties']) || !is_array($data['properties'])) {
            throw new \InvalidArgumentException('UpdateNodePatch requires "properties" array');
        }

        return new self($data['nodeId'], $data['properties']);
    }

    /**
     * @return array{operation: string, nodeId: string, properties: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
            'properties' => $this->properties,
        ];
    }
}

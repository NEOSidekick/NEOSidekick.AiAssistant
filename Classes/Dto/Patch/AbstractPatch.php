<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Base class for all patch operations.
 *
 * @Flow\Proxy(false)
 */
abstract class AbstractPatch implements JsonSerializable
{
    protected string $operation;

    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Create a patch from an associative array.
     *
     * @param array{operation: string, nodeId?: string, parentNodeId?: string, nodeType?: string, position?: string, targetNodeId?: string, properties?: array<string, mixed>} $data
     * @return AbstractPatch
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): AbstractPatch
    {
        if (!isset($data['operation'])) {
            throw new \InvalidArgumentException('Patch must contain an "operation" field');
        }

        return match ($data['operation']) {
            'createNode' => CreateNodePatch::fromArray($data),
            'updateNode' => UpdateNodePatch::fromArray($data),
            'moveNode' => MoveNodePatch::fromArray($data),
            'deleteNode' => DeleteNodePatch::fromArray($data),
            default => throw new \InvalidArgumentException(sprintf('Unknown operation "%s"', $data['operation'])),
        };
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function jsonSerialize(): array;
}

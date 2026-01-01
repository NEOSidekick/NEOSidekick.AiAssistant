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

    /**
     * Return the patch operation type.
     *
     * The operation identifies the concrete patch action and will be one of:
     * `createNode`, `updateNode`, `moveNode`, or `deleteNode`.
     *
     * @return string The operation type.
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Create a concrete patch instance from an associative array.
     *
     * @param array{operation: string, nodeId?: string, positionRelativeToNodeId?: string, nodeType?: string, position?: string, targetNodeId?: string, properties?: array<string, mixed>} $data Associative array describing the patch; must include the `operation` key.
     * @return AbstractPatch An AbstractPatch instance representing the parsed patch.
     * @throws \InvalidArgumentException If the `operation` key is missing or contains an unknown operation value.
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
 * Produce an associative array representation of the patch.
 *
 * @return array<string, mixed> Associative array representing the patch data.
 */
    abstract public function jsonSerialize(): array;
}
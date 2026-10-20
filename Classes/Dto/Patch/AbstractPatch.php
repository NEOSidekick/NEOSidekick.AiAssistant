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
     * Batch-local name declared by a createNode patch; later patches address the created node as
     * `$<ref>`. Carried for every operation so the validator can reject it where it is not allowed.
     */
    protected ?string $ref = null;

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getRef(): ?string
    {
        return $this->ref;
    }

    /**
     * Create a patch from an associative array.
     *
     * @param array{operation: string, ref?: string, nodeId?: string, positionRelativeToNodeId?: string, nodeType?: string, position?: string, targetNodeId?: string, properties?: array<string, mixed>} $data
     * @return AbstractPatch
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data): AbstractPatch
    {
        if (!isset($data['operation'])) {
            throw new \InvalidArgumentException('Patch must contain an "operation" field');
        }

        $patch = match ($data['operation']) {
            'createNode' => CreateNodePatch::fromArray($data),
            'updateNode' => UpdateNodePatch::fromArray($data),
            'moveNode' => MoveNodePatch::fromArray($data),
            'deleteNode' => DeleteNodePatch::fromArray($data),
            default => throw new \InvalidArgumentException(sprintf('Unknown operation "%s"', $data['operation'])),
        };

        if (isset($data['ref'])) {
            if (!is_string($data['ref'])) {
                throw new \InvalidArgumentException('Field "ref" must be a string');
            }
            $patch->ref = $data['ref'];
        }

        return $patch;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function jsonSerialize(): array;
}

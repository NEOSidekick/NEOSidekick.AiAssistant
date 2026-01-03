<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for moving a node.
 *
 * @Flow\Proxy(false)
 */
final class MoveNodePatch extends AbstractPatch
{
    /**
     * Valid position values for node placement.
     */
    private const VALID_POSITIONS = ['into', 'before', 'after'];

    protected string $operation = 'moveNode';

    private string $nodeId;

    private string $targetNodeId;

    /**
     * Position: 'into', 'before', or 'after'
     */
    private string $position;

    public function __construct(string $nodeId, string $targetNodeId, string $position = 'into')
    {
        self::validatePosition($position);
        $this->nodeId = $nodeId;
        $this->targetNodeId = $targetNodeId;
        $this->position = $position;
    }

    /**
     * Validate that the position is one of the allowed values.
     *
     * @throws \InvalidArgumentException
     */
    private static function validatePosition(string $position): void
    {
        if (!in_array($position, self::VALID_POSITIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid position "%s". Must be one of: %s',
                    $position,
                    implode(', ', self::VALID_POSITIONS)
                )
            );
        }
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function getTargetNodeId(): string
    {
        return $this->targetNodeId;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * @param array{operation: string, nodeId: string, targetNodeId: string, position?: string} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId'])) {
            throw new \InvalidArgumentException('MoveNodePatch requires "nodeId"');
        }
        if (!isset($data['targetNodeId'])) {
            throw new \InvalidArgumentException('MoveNodePatch requires "targetNodeId"');
        }

        return new self(
            $data['nodeId'],
            $data['targetNodeId'],
            $data['position'] ?? 'into'
        );
    }

    /**
     * @return array{operation: string, nodeId: string, targetNodeId: string, position: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
            'targetNodeId' => $this->targetNodeId,
            'position' => $this->position,
        ];
    }
}

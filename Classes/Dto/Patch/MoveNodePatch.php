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
    protected string $operation = 'moveNode';

    private string $nodeId;

    private string $targetNodeId;

    /**
     * Position: 'into', 'before', or 'after'
     */
    private string $position;

    public function __construct(string $nodeId, string $targetNodeId, string $position = 'into')
    {
        $this->nodeId = $nodeId;
        $this->targetNodeId = $targetNodeId;
        $this->position = $position;
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

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

    /**
     * Create a patch representing moving a node to a target node at a specified position.
     *
     * @param string $nodeId The identifier of the node to move.
     * @param string $targetNodeId The identifier of the target node.
     * @param string $position Placement relative to the target: 'into', 'before', or 'after'.
     * @throws \InvalidArgumentException If `$position` is not one of the allowed values.
     */
    public function __construct(string $nodeId, string $targetNodeId, string $position = 'into')
    {
        self::validatePosition($position);
        $this->nodeId = $nodeId;
        $this->targetNodeId = $targetNodeId;
        $this->position = $position;
    }

    /**
     * Ensure the provided position is one of the allowed placement values.
     *
     * @param string $position The placement position; allowed values: 'into', 'before', 'after'.
     * @throws \InvalidArgumentException If $position is not one of the allowed values.
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

    /**
     * Retrieve the identifier of the node being moved.
     *
     * @return string The identifier of the node to move.
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Gets the target node identifier.
     *
     * @return string The UUID or identifier of the target node.
     */
    public function getTargetNodeId(): string
    {
        return $this->targetNodeId;
    }

    /**
     * Gets the placement position used when moving the node.
     *
     * @return string One of 'into', 'before', or 'after' indicating how the node should be placed relative to the target node.
     */
    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * Create a MoveNodePatch from an associative array.
     *
     * @param array{operation: string, nodeId: string, targetNodeId: string, position?: string} $data Associative array containing 'nodeId' and 'targetNodeId' (required) and optional 'position' (one of 'into', 'before', 'after').
     * @return self A MoveNodePatch instance populated from the provided data.
     * @throws \InvalidArgumentException If 'nodeId' or 'targetNodeId' is not present in $data.
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
     * Serialize the patch into an associative array for JSON encoding.
     *
     * @return array{operation: string, nodeId: string, targetNodeId: string, position: string} Associative array with keys 'operation', 'nodeId', 'targetNodeId', and 'position'.
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
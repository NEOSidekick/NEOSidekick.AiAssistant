<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for deleting a node.
 *
 * @Flow\Proxy(false)
 */
final class DeleteNodePatch extends AbstractPatch
{
    protected string $operation = 'deleteNode';

    private string $nodeId;

    /**
     * Initialize the patch with the target node identifier.
     *
     * @param string $nodeId The identifier of the node to delete.
     */
    public function __construct(string $nodeId)
    {
        $this->nodeId = $nodeId;
    }

    /**
     * Get the node identifier targeted by this patch.
     *
     * @return string The node identifier.
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Create a DeleteNodePatch from an associative array.
     *
     * @param array{operation: string, nodeId: string} $data Array with keys `operation` and `nodeId`; `nodeId` is required.
     * @return self The created DeleteNodePatch instance.
     * @throws \InvalidArgumentException If the `nodeId` key is missing.
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId'])) {
            throw new \InvalidArgumentException('DeleteNodePatch requires "nodeId"');
        }

        return new self($data['nodeId']);
    }

    /**
     * Serialize the patch to an associative array representation.
     *
     * @return array{operation: string, nodeId: string} Associative array containing `operation` (the patch operation name) and `nodeId` (the target node identifier).
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
        ];
    }
}
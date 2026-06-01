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

    public function __construct(string $nodeId)
    {
        $this->nodeId = $nodeId;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * @param array{operation: string, nodeId: string} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId'])) {
            throw new \InvalidArgumentException('DeleteNodePatch requires "nodeId"');
        }

        return new self($data['nodeId']);
    }

    /**
     * @return array{operation: string, nodeId: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
        ];
    }
}

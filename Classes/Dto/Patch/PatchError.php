<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Error information for a failed patch operation.
 *
 * @Flow\Proxy(false)
 */
final class PatchError implements JsonSerializable
{
    private string $message;

    private int $patchIndex;

    private string $operation;

    private ?string $nodeId;

    public function __construct(string $message, int $patchIndex, string $operation, ?string $nodeId = null)
    {
        $this->message = $message;
        $this->patchIndex = $patchIndex;
        $this->operation = $operation;
        $this->nodeId = $nodeId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getPatchIndex(): int
    {
        return $this->patchIndex;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    /**
     * @return array{message: string, patchIndex: int, operation: string, nodeId: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'patchIndex' => $this->patchIndex,
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
        ];
    }
}

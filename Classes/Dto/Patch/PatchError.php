<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Error information for a failed patch operation.
 *
 * `nodeId` is always a node id or null. A batch-local reference (`$<ref>` or `$<ref>/<childName>`)
 * passed as `nodeId` is moved to `ref`, exactly as the client sent it, so no call site can leak an
 * alias into the id field.
 *
 * @Flow\Proxy(false)
 */
final class PatchError implements JsonSerializable
{
    private string $message;

    private int $patchIndex;

    private string $operation;

    private ?string $nodeId;

    private ?string $ref;

    public function __construct(string $message, int $patchIndex, string $operation, ?string $nodeId = null)
    {
        $this->message = $message;
        $this->patchIndex = $patchIndex;
        $this->operation = $operation;
        if ($nodeId !== null && RefAnchor::isAnchor($nodeId)) {
            $this->nodeId = null;
            $this->ref = $nodeId;
        } else {
            $this->nodeId = $nodeId;
            $this->ref = null;
        }
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
     * The batch-local reference the failing patch used, e.g. `$acc` or `$acc/main`; null otherwise.
     */
    public function getRef(): ?string
    {
        return $this->ref;
    }

    /**
     * @return array{message: string, patchIndex: int, operation: string, nodeId: string|null, ref: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'patchIndex' => $this->patchIndex,
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
            'ref' => $this->ref,
        ];
    }
}

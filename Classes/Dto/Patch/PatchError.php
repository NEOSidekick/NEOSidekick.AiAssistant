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

    /**
     * Create a PatchError DTO containing details about a failed patch operation.
     *
     * @param string      $message    Human-readable error message.
     * @param int         $patchIndex Index of the patch that failed.
     * @param string      $operation  The patch operation that was being performed.
     * @param string|null $nodeId     Optional identifier of the affected node.
     */
    public function __construct(string $message, int $patchIndex, string $operation, ?string $nodeId = null)
    {
        $this->message = $message;
        $this->patchIndex = $patchIndex;
        $this->operation = $operation;
        $this->nodeId = $nodeId;
    }

    /**
     * Retrieve the human-readable error message describing the patch failure.
     *
     * @return string The error message.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the index of the failed patch.
     *
     * @return int The index of the patch that failed.
     */
    public function getPatchIndex(): int
    {
        return $this->patchIndex;
    }

    /**
     * Gets the patch operation associated with this error.
     *
     * @return string The patch operation that was being performed when the error occurred.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Retrieve the optional identifier of the affected node.
     *
     * @return string|null The node ID, or `null` if not set.
     */
    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }

    /**
     * Convert the patch error into an associative array for JSON serialization.
     *
     * The array contains the keys 'message', 'patchIndex', 'operation', and 'nodeId' (which may be null).
     *
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
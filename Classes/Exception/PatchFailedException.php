<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Exception;

use Neos\Flow\Annotations as Flow;

/**
 * Exception thrown when a patch operation fails.
 *
 * @Flow\Proxy(false)
 */
class PatchFailedException extends \Exception
{
    private int $patchIndex;

    private string $operation;

    private ?string $nodeId;

    /**
     * Create an exception that represents a failed patch operation and carries related context.
     *
     * @param string $message A human-readable message describing the failure.
     * @param int $patchIndex The zero-based index of the patch that failed.
     * @param string $operation The patch operation that was attempted (e.g., "replace", "add", "remove").
     * @param string|null $nodeId Optional identifier of the node affected by the patch.
     * @param \Throwable|null $previous Optional previous exception for chaining.
     */
    public function __construct(
        string $message,
        int $patchIndex,
        string $operation,
        ?string $nodeId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 1735648800, $previous);
        $this->patchIndex = $patchIndex;
        $this->operation = $operation;
        $this->nodeId = $nodeId;
    }

    /**
     * Retrieve the index of the patch that failed.
     *
     * @return int The index of the patch that failed.
     */
    public function getPatchIndex(): int
    {
        return $this->patchIndex;
    }

    /**
     * Get the operation name associated with the failed patch.
     *
     * @return string The operation that was attempted when the patch failed.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Retrieve the node identifier associated with the failed patch, if available.
     *
     * @return string|null The node identifier related to the patch, or `null` if no node id was provided.
     */
    public function getNodeId(): ?string
    {
        return $this->nodeId;
    }
}
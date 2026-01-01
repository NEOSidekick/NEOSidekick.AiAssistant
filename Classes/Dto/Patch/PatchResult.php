<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * Result of applying patches.
 *
 * For createNode operations, results include a 'createdNodes' array with
 * information about all nodes that were created, including auto-created
 * child nodes and nodes from NodeTemplates.
 *
 * @Flow\Proxy(false)
 */
final class PatchResult implements JsonSerializable
{
    private bool $success;

    private bool $dryRun;

    /**
     * Results for each patch operation.
     * For createNode: includes 'createdNodes' with all created node details.
     * For other operations: just index, operation, and nodeId.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $results;

    private ?PatchError $error;

    private bool $rollbackPerformed;

    /**
     * Initialize the PatchResult with its outcome, per-patch results, optional error and rollback flag.
     *
     * @param bool $success Whether the patch operation succeeded.
     * @param bool $dryRun Whether the operation was a dry run (no persistent changes).
     * @param array<int, array<string, mixed>> $results Per-patch result entries (present when $success is true).
     * @param PatchError|null $error Error details (present when $success is false).
     * @param bool $rollbackPerformed Whether a rollback was performed after failure.
     */
    private function __construct(
        bool $success,
        bool $dryRun,
        array $results,
        ?PatchError $error,
        bool $rollbackPerformed
    ) {
        $this->success = $success;
        $this->dryRun = $dryRun;
        $this->results = $results;
        $this->error = $error;
        $this->rollbackPerformed = $rollbackPerformed;
    }

    /**
         * Create a PatchResult representing a successful patch application.
         *
         * @param bool $dryRun Whether the operation was a dry run.
         * @param array<int, array<string, mixed>> $results Per-patch result entries returned by the operation.
         * @return self A PatchResult representing success.
         */
    public static function success(bool $dryRun, array $results): self
    {
        return new self(true, $dryRun, $results, null, false);
    }

    /**
     * Constructs a PatchResult representing a failed patch operation.
     *
     * @param bool $dryRun Whether the patch run was a dry run (no changes applied).
     * @param PatchError $error Error information describing the failure.
     * @param bool $rollbackPerformed Whether a rollback was performed as a result of the failure.
     * @return self The failure PatchResult instance.
     */
    public static function failure(bool $dryRun, PatchError $error, bool $rollbackPerformed = true): self
    {
        return new self(false, $dryRun, [], $error, $rollbackPerformed);
    }

    /**
     * Indicates whether the patch operation completed successfully.
     *
     * @return bool `true` if the overall patch operation succeeded, `false` otherwise.
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Indicates whether the patch operation was executed as a dry run.
     *
     * @return bool `true` if the operation was a dry run, `false` otherwise.
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * The per-patch operation results produced when a patch is applied.
     *
     * Each element is an associative array describing a single operation. For `createNode` operations the array includes a `createdNodes` key with details of created nodes; other operations typically include keys such as `operation` and `nodeId`.
     *
     * @return array<int, array<string, mixed>> Array of per-operation result payloads.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get the error information for a failed patch operation.
     *
     * @return PatchError|null The error details when the patch application failed, or `null` if the operation succeeded or no error is present.
     */
    public function getError(): ?PatchError
    {
        return $this->error;
    }

    /**
     * Indicates whether a rollback was performed after applying patches.
     *
     * @return bool `true` if a rollback was performed, `false` otherwise.
     */
    public function isRollbackPerformed(): bool
    {
        return $this->rollbackPerformed;
    }

    /**
         * Serialize the patch result into an associative array for JSON encoding.
         *
         * The returned array always contains the keys `success` and `dryRun`.
         * If the result represents a successful operation the `results` key is included.
         * If the result represents a failure the `error` and `rollbackPerformed` keys are included.
         *
         * @return array<string, mixed> Associative array with keys:
         *                              - `success` (bool)
         *                              - `dryRun` (bool)
         *                              - `results` (array) present when `success` is true
         *                              - `error` (PatchError|null) present when `success` is false
         *                              - `rollbackPerformed` (bool) present when `success` is false
         */
    public function jsonSerialize(): array
    {
        $data = [
            'success' => $this->success,
            'dryRun' => $this->dryRun,
        ];

        if ($this->success) {
            $data['results'] = $this->results;
        } else {
            $data['error'] = $this->error;
            $data['rollbackPerformed'] = $this->rollbackPerformed;
        }

        return $data;
    }
}
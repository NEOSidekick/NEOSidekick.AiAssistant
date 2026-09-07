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
     * @param bool $success
     * @param array<int, array<string, mixed>> $results
     * @param PatchError|null $error
     * @param bool $rollbackPerformed
     */
    private function __construct(
        bool $success,
        array $results,
        ?PatchError $error,
        bool $rollbackPerformed
    ) {
        $this->success = $success;
        $this->results = $results;
        $this->error = $error;
        $this->rollbackPerformed = $rollbackPerformed;
    }

    /**
     * Create a success result.
     *
     * @param array<int, array<string, mixed>> $results
     * @return self
     */
    public static function success(array $results): self
    {
        return new self(true, $results, null, false);
    }

    /**
     * Create a failure result.
     *
     * @param PatchError $error
     * @param bool $rollbackPerformed Whether a transaction was opened and rolled back (false for a validation refusal)
     * @return self
     */
    public static function failure(PatchError $error, bool $rollbackPerformed = true): self
    {
        return new self(false, [], $error, $rollbackPerformed);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    public function getError(): ?PatchError
    {
        return $this->error;
    }

    public function isRollbackPerformed(): bool
    {
        return $this->rollbackPerformed;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'success' => $this->success,
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

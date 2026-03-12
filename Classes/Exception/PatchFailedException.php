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
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Exception;

use Neos\Flow\Annotations as Flow;

/**
 * Exception thrown when agent token generation fails.
 *
 * @Flow\Proxy(false)
 */
class AgentTokenException extends \Exception
{
    private int $statusCode;

    private string $errorType;

    public function __construct(
        string $message,
        int $statusCode = 500,
        string $errorType = 'Internal Server Error',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->statusCode = $statusCode;
        $this->errorType = $errorType;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Exception;

use Neos\Flow\Annotations as Flow;
use RuntimeException;

/**
 * The signing-key row could not be READ - as opposed to "there is no signing key".
 * A missing key is an authoritative rejection, while a failed load is inconclusive
 * and must never de-authorize an editor.
 *
 * @Flow\Proxy(false)
 */
class AgentSigningKeyStorageException extends RuntimeException
{
}

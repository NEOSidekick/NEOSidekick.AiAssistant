<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Exception;

use Neos\Flow\Annotations as Flow;

/**
 * The content dimensions an API caller asked for cannot be used on this site. The message is
 * written for the caller: it says what is wrong and names what is configured.
 *
 * @Flow\Proxy(false)
 */
class InvalidContentDimensionsException extends \Exception
{
}

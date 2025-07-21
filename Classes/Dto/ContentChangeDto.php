<?php
declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class ContentChangeDto
{
    public function __construct(
        public readonly ?NodeDataDto $before,
        public readonly ?NodeDataDto $after
    ) {
    }
}

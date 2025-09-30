<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class NodeDataDto
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $path,
        public readonly string $workspace,
        public readonly array $dimensions,
        public readonly string $name,
        public readonly ?array $properties
    ) {
    }
}

<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
class NodeContextPathDto
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $path,
        public readonly string $workspace,
        public readonly array $dimensions
    ) {
    }

    /**
     * Convert this DTO to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'path' => $this->path,
            'workspace' => $this->workspace,
            'dimensions' => $this->dimensions
        ];
    }
}

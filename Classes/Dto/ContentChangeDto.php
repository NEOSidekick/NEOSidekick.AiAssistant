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

    public function getChangeType(): string
    {
        if ($this->before === null && $this->after !== null) {
            return 'created';
        }

        if ($this->before !== null && $this->after === null) {
            return 'removed';
        }

        return 'updated';
    }

    public function toArray(): array
    {
        $sourceDto = $this->after ?? $this->before;
        if ($sourceDto === null) {
            // This case should not happen in a valid lifecycle, but as a safeguard:
            return [];
        }

        $changeType = $this->getChangeType();
        $propertiesAfter = $changeType === 'removed' ? null : $this->after?->properties;

        return [
            'nodeContextPath' => [
                'identifier' => $sourceDto->identifier,
                'path' => $sourceDto->path,
                'workspace' => $sourceDto->workspace,
                'dimensions' => $sourceDto->dimensions,
            ],
            'name' => $sourceDto->name,
            'changeType' => $changeType,
            'propertiesBefore' => $this->before?->properties,
            'propertiesAfter' => $propertiesAfter,
        ];
    }
}

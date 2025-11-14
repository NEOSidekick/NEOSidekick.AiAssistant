<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class ImageTextPropertyConfigurationDto
{
    private string $imagePropertyName;
    private string $nodeTypeKey;
    private string $textPropertyName;
    public function __construct(
        string $imagePropertyName,
        string $nodeTypeKey,
        string $textPropertyName,
    ) {
        $this->imagePropertyName = $imagePropertyName;
        $this->nodeTypeKey = $nodeTypeKey;
        $this->textPropertyName = $textPropertyName;
    }

    public function getImagePropertyName(): string
    {
        return $this->imagePropertyName;
    }

    public function getNodeTypeKey(): string
    {
        return $this->nodeTypeKey;
    }

    public function getTextPropertyName(): string
    {
        return $this->textPropertyName;
    }
}

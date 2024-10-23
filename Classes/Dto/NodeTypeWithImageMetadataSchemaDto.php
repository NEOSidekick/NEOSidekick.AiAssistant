<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class NodeTypeWithImageMetadataSchemaDto implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $nodeTypeName;

    /**
     * @var string
     */
    protected string $imagePropertyName;

    /**
     * @var string|null
     */
    protected ?string $alternativeTextPropertyName;

    /**
     * @var string|null
     */
    protected ?string $titleTextPropertyName;

    /**
     * @param string      $nodeTypeName
     * @param string      $imagePropertyName
     * @param string|null $alternativeTextPropertyName
     * @param string|null $titleTextPropertyName
     */
    public function __construct(
        string $nodeTypeName,
        string $imagePropertyName,
        ?string $alternativeTextPropertyName = null,
        ?string $titleTextPropertyName = null
    ) {
        $this->nodeTypeName = $nodeTypeName;
        $this->imagePropertyName = $imagePropertyName;
        $this->alternativeTextPropertyName = $alternativeTextPropertyName;
        $this->titleTextPropertyName = $titleTextPropertyName;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getImagePropertyName(): string
    {
        return $this->imagePropertyName;
    }

    public function getAlternativeTextPropertyName(): ?string
    {
        return $this->alternativeTextPropertyName;
    }

    public function getTitleTextPropertyName(): ?string
    {
        return $this->titleTextPropertyName;
    }

    public function jsonSerialize(): array
    {
        return [
            'nodeTypeName' => $this->nodeTypeName,
            'imagePropertyName' => $this->imagePropertyName,
            'alternativeTextPropertyName' => $this->alternativeTextPropertyName,
            'titleTextPropertyName' => $this->titleTextPropertyName
        ];
    }
}

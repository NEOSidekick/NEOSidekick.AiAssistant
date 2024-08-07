<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindImageData implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $nodeContextPath;

    /**
     * @var string
     */
    protected string $nodeTypeName;

    /**
     * @var string
     */
    protected string $filename;

    /**
     * @var string
     */
    protected string $fullsizeUri;

    /**
     * @var string
     */
    protected string $thumbnailUri;

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
    protected ?string $alternativeTextPropertyValue;

    /**
     * @var string|null
     */
    protected ?string $titleTextPropertyName;

    /**
     * @var string|null
     */
    protected ?string $titleTextPropertyValue;

    public function __construct(
        string $nodeContextPath,
        string $nodeTypeName,
        string $filename,
        string $fullsizeUri,
        string $thumbnailUri,
        string $imagePropertyName,
        ?string $alternativeTextPropertyName,
        ?string $alternativeTextPropertyValue,
        ?string $titleTextPropertyName,
        ?string $titleTextPropertyValue
    ) {
        $this->nodeContextPath = $nodeContextPath;
        $this->nodeTypeName = $nodeTypeName;
        $this->filename = $filename;
        $this->fullsizeUri = $fullsizeUri;
        $this->thumbnailUri = $thumbnailUri;
        $this->imagePropertyName = $imagePropertyName;
        $this->alternativeTextPropertyName = $alternativeTextPropertyName;
        $this->alternativeTextPropertyValue = $alternativeTextPropertyValue;
        $this->titleTextPropertyName = $titleTextPropertyName;
        $this->titleTextPropertyValue = $titleTextPropertyValue;
    }

    public function getNodeContextPath(): string
    {
        return $this->nodeContextPath;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getFullsizeUri(): string
    {
        return $this->fullsizeUri;
    }

    public function getThumbnailUri(): string
    {
        return $this->thumbnailUri;
    }

    public function getImagePropertyName(): string
    {
        return $this->imagePropertyName;
    }

    public function getAlternativeTextPropertyName(): ?string
    {
        return $this->alternativeTextPropertyName;
    }

    public function getAlternativeTextPropertyValue(): ?string
    {
        return $this->alternativeTextPropertyValue;
    }

    public function gettitleTextPropertyName(): ?string
    {
        return $this->titleTextPropertyName;
    }

    public function gettitleTextPropertyValue(): ?string
    {
        return $this->titleTextPropertyValue;
    }
    public function jsonSerialize(): array
    {
        return [
            'nodeContextPath' => $this->nodeContextPath,
            'nodeTypeName' => $this->nodeTypeName,
            'filename' => $this->filename,
            'fullsizeUri' => $this->fullsizeUri,
            'thumbnailUri' => $this->thumbnailUri,
            'imagePropertyName' => $this->imagePropertyName,
            'alternativeTextPropertyName' => $this->alternativeTextPropertyName,
            'alternativeTextPropertyValue' => $this->alternativeTextPropertyValue,
            'titleTextPropertyName' => $this->titleTextPropertyName,
            'titleTextPropertyValue' => $this->titleTextPropertyValue
        ];
    }
}

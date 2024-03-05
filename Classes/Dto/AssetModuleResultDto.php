<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class AssetModuleResultDto
{
    /**
     * @var string
     */
    protected string $filename;
    /**
     * @var string
     */
    protected string $assetIdentifier;
    /**
     * @var string
     */
    protected string $thumbnailUri;
    /**
     * @var string
     */
    protected string $fullsizeUri;
    /**
     * @var string
     */
    protected string $propertyName;
    /**
     * @var string
     */
    protected string $propertyValue;

    /**
     * @param string $assetIdentifier
     * @param string $thumbnailUri
     * @param string $fullsizeUri
     * @param string $propertyName
     * @param string $propertyValue
     */
    public function __construct(
        string $filename,
        string $assetIdentifier,
        string $thumbnailUri,
        string $fullsizeUri,
        string $propertyName,
        string $propertyValue
    ) {
        $this->filename = $filename;
        $this->assetIdentifier = $assetIdentifier;
        $this->thumbnailUri = $thumbnailUri;
        $this->fullsizeUri = $fullsizeUri;
        $this->propertyName = $propertyName;
        $this->propertyValue = $propertyValue;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getAssetIdentifier(): string
    {
        return $this->assetIdentifier;
    }

    public function getThumbnailUri(): string
    {
        return $this->thumbnailUri;
    }

    public function getFullsizeUri(): string
    {
        return $this->fullsizeUri;
    }

    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getPropertyValue(): string
    {
        return $this->propertyValue;
    }

    public static function fromArray(array $array): self
    {
        return new self(
            $array['filename'],
            $array['assetIdentifier'],
            $array['thumbnailUri'],
            $array['fullsizeUri'],
            $array['propertyName'],
            $array['propertyValue']
        );
    }
}

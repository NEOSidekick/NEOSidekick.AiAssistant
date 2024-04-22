<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class AssetModuleResultDto implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $filename;
    /**
     * @var string
     */
    protected string $identifier;
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
     * @param string $filename
     * @param string $identifier
     * @param string $thumbnailUri
     * @param string $fullsizeUri
     * @param string $propertyName
     * @param string $propertyValue
     */
    public function __construct(
        string $filename,
        string $identifier,
        string $thumbnailUri,
        string $fullsizeUri,
        string $propertyName,
        string $propertyValue
    ) {
        $this->filename = $filename;
        $this->identifier = $identifier;
        $this->thumbnailUri = $thumbnailUri;
        $this->fullsizeUri = $fullsizeUri;
        $this->propertyName = $propertyName;
        $this->propertyValue = $propertyValue;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
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
            $array['identifier'],
            $array['thumbnailUri'],
            $array['fullsizeUri'],
            $array['propertyName'],
            $array['propertyValue']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'filename' => $this->filename,
            'thumbnailUri' => $this->thumbnailUri,
            'fullsizeUri' => $this->fullsizeUri,
            'propertyName' => $this->propertyName,
            'propertyValue' => $this->propertyValue,
            'type' => 'Asset'
        ];
    }
}

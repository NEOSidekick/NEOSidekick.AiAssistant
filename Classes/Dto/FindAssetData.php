<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindAssetData implements JsonSerializable
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
     * @var array
     */
    protected array $properties;

    /**
     * @param string $filename
     * @param string $identifier
     * @param string $thumbnailUri
     * @param string $fullsizeUri
     * @param array $properties
     */
    public function __construct(
        string $filename,
        string $identifier,
        string $thumbnailUri,
        string $fullsizeUri,
        array $properties
    ) {
        $this->filename = $filename;
        $this->identifier = $identifier;
        $this->thumbnailUri = $thumbnailUri;
        $this->fullsizeUri = $fullsizeUri;
        $this->properties = $properties;
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

    public function getProperties(): array
    {
        return $this->properties;
    }

    public static function fromArray(array $array): self
    {
        return new self(
            $array['filename'],
            $array['identifier'],
            $array['thumbnailUri'],
            $array['fullsizeUri'],
            $array['properties']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Asset',
            'identifier' => $this->identifier,
            'filename' => $this->filename,
            'thumbnailUri' => $this->thumbnailUri,
            'fullsizeUri' => $this->fullsizeUri,
            'properties' => $this->properties,
        ];
    }
}

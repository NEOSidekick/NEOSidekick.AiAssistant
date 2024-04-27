<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FocusKeywordListItem implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $identifier;

    /**
     * @var string
     */
    protected string $nodeContextPath;

    /**
     * @var string
     */
    protected string $publicUri;

    /**
     * @var string
     */
    protected string $nodeTypeName;

    /**
     * @var array
     */
    protected array $properties;

    /**
     * @var string
     */
    protected string $language;

    /**
     * @param string $identifier
     * @param string $nodeContextPath
     * @param string $nodeTypeName
     * @param string $publicUri
     * @param array $properties
     * @param string $language
     */
    public function __construct(string $identifier, string $nodeContextPath, string $nodeTypeName, string $publicUri, array $properties, string $language)
    {
        $this->identifier = $identifier;
        $this->nodeContextPath = $nodeContextPath;
        $this->nodeTypeName = $nodeTypeName;
        $this->publicUri = $publicUri;
        $this->properties = $properties;
        $this->language = $language;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getNodeContextPath(): string
    {
        return $this->nodeContextPath;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getPublicUri(): string
    {
        return $this->publicUri;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @param array{
     *     identifier: string,
     *     nodeContextPath: string,
     *      nodeTypeName: string,
     *     publicUri: string,
     *     properties: array,
     *     language: string
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['identifier'],
            $array['nodeContextPath'],
            $array['nodeTypeName'],
            $array['publicUri'],
            $array['properties'],
            $array['language']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'nodeContextPath' => $this->nodeContextPath,
            'nodeTypeName' => $this->nodeTypeName,
            'publicUri' => $this->publicUri,
            'properties' => $this->properties,
            'language' => $this->language,
            'type' => 'DocumentNode'
        ];
    }
}

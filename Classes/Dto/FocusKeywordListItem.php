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
    protected string $pageTitle;

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
     * @param string $publicUri
     * @param string $pageTitle
     * @param array $properties
     * @param string $language
     */
    public function __construct(string $identifier, string $nodeContextPath, string $publicUri, string $pageTitle, array $properties, string $language)
    {
        $this->identifier = $identifier;
        $this->nodeContextPath = $nodeContextPath;
        $this->publicUri = $publicUri;
        $this->pageTitle = $pageTitle;
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

    public function getPublicUri(): string
    {
        return $this->publicUri;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
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
     *     publicUri: string,
     *     pageTitle: string,
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
            $array['publicUri'],
            $array['pageTitle'],
            $array['properties'],
            $array['language']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'nodeContextPath' => $this->nodeContextPath,
            'publicUri' => $this->publicUri,
            'pageTitle' => $this->pageTitle,
            'properties' => $this->properties,
            'language' => $this->language,
            'type' => 'DocumentNode'
        ];
    }
}

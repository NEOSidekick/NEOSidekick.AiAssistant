<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;
use Neos\Utility\ObjectAccess;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FocusKeywordModuleResultDto implements \JsonSerializable
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
     * @var string
     */
    protected string $focusKeyword;

    /**
     * @var string
     */
    protected string $language;

    /**
     * @param string $identifier
     * @param string $nodeContextPath
     * @param string $publicUri
     * @param string $pageTitle
     * @param string $focusKeyword
     * @param string $language
     */
    public function __construct(string $identifier, string $nodeContextPath, string $publicUri, string $pageTitle, string $focusKeyword, string $language)
    {
        $this->identifier = $identifier;
        $this->nodeContextPath = $nodeContextPath;
        $this->publicUri = $publicUri;
        $this->pageTitle = $pageTitle;
        $this->focusKeyword = $focusKeyword;
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

    public function getFocusKeyword(): string
    {
        return $this->focusKeyword;
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
     *     focusKeyword: string,
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
            $array['focusKeyword'],
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
            'focusKeyword' => $this->focusKeyword,
            'language' => $this->language
        ];
    }
}

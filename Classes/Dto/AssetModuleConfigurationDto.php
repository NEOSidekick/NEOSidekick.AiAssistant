<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class AssetModuleConfigurationDto
{
    /**
     * @var bool
     */
    protected bool $onlyAssetsInUse;
    /**
     * @var string
     */
    protected string $propertyName;
    /**
     * @var int
     */
    protected int $limit;
    /**
     * @var int
     */
    protected int $firstResult;
    /**
     * @var string
     */
    protected string $language;

    /**
     * @param bool   $onlyAssetsInUse
     * @param string $propertyName
     * @param int    $limit
     * @param string $language
     */
    public function __construct(bool $onlyAssetsInUse, string $propertyName, int $limit = 10, string $language = 'en', int $firstResult = 0)
    {
        $this->onlyAssetsInUse = $onlyAssetsInUse;
        $this->propertyName = $propertyName;
        $this->limit = $limit;
        $this->language = $language;
        $this->firstResult = $firstResult;
    }

    public function isOnlyAssetsInUse(): bool
    {
        return $this->onlyAssetsInUse;
    }

    /**
     * @return string{"title", "caption", "copyrightNotice"}
     */
    public function getPropertyName(): string
    {
        return $this->propertyName;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getFirstResult(): int
    {
        return $this->firstResult;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }
}

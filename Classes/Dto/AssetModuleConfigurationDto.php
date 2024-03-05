<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class AssetModuleConfigurationDto
{
    protected bool $onlyAssetsInUse;
    protected string $propertyName;
    protected int $limit;
    protected string $language;

    /**
     * @param bool   $onlyAssetsInUse
     * @param string $propertyName
     * @param int    $limit
     * @param string $language
     */
    public function __construct(bool $onlyAssetsInUse, string $propertyName, int $limit = 10, string $language = 'en')
    {
        $this->onlyAssetsInUse = $onlyAssetsInUse;
        $this->propertyName = $propertyName;
        $this->limit = $limit;
        $this->language = $language;
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
}

<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindAssetsFilterDto
{
    /**
     * @var bool
     */
    protected bool $onlyAssetsInUse;
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
    private string $propertyNameMustBeEmpty;

    /**
     * @param bool   $onlyAssetsInUse
     * @param string $propertyNameMustBeEmpty
     * @param int    $firstResult
     * @param int    $limit
     */
    public function __construct(
        bool $onlyAssetsInUse,
        string $propertyNameMustBeEmpty = '',
        int $firstResult = 0,
        int $limit = 10
    ) {
        $this->onlyAssetsInUse = $onlyAssetsInUse;
        $this->limit = $limit;
        $this->firstResult = $firstResult;
        $this->propertyNameMustBeEmpty = $propertyNameMustBeEmpty;
    }

    public function isOnlyAssetsInUse(): bool
    {
        return $this->onlyAssetsInUse;
    }

    public function getPropertyNameMustBeEmpty(): ?string
    {
        return $this->propertyNameMustBeEmpty;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getFirstResult(): int
    {
        return $this->firstResult;
    }
}

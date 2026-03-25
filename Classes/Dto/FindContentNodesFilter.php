<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindContentNodesFilter implements LanguageDimensionFilterInterface
{
    /**
     * @var string
     */
    protected string $workspace;

    /**
     * @var string[]
     */
    protected array $languageDimensionFilter = [];

    /**
     * 'none' or 'only-empty'
     * @var string
     */
    protected string $alternativeTextFilter = 'none';

    public function __construct(
        string $workspace,
        string $alternativeTextFilter = 'none',
        ?string $languageDimensionFilter = null
    ) {
        $this->workspace = $workspace;
        $this->alternativeTextFilter = $alternativeTextFilter;
        $this->languageDimensionFilter = $languageDimensionFilter ? explode(',', $languageDimensionFilter) : [];
    }

    public function getWorkspace(): ?string
    {
        return $this->workspace;
    }

    public function getLanguageDimensionFilter(): ?array
    {
        return $this->languageDimensionFilter;
    }

    public function getAlternativeTextFilter(): ?string
    {
        return $this->alternativeTextFilter;
    }
}

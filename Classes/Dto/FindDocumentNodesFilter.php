<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindDocumentNodesFilter
{
    /**
     * @var string
     */
    protected string $workspace;

    /**
     * 'none', 'only-empty-focus-keywords', 'only-existing-focus-keywords'
     * @var string
     */
    protected string $seoPropertiesFilter;

    /**
     * 'none', 'only-empty-focus-keywords', 'only-existing-focus-keywords'
     * @var string
     */
    protected string $focusKeywordPropertyFilter;

    /**
     * @var string|null
     */
    protected ?string $languageDimensionFilter = null;

    /**
     * @var string|null
     */
    protected ?string $nodeTypeFilter = null;

    /**
     * @param string      $workspace
     * @param string|null $seoPropertiesFilter
     * @param string|null $focusKeywordPropertyFilter
     * @param string|null $languageDimensionFilter
     * @param string|null $nodeTypeFilter
     */
    public function __construct(
        string  $workspace,
        ?string $seoPropertiesFilter = 'none',
        ?string $focusKeywordPropertyFilter = 'none',
        ?string $languageDimensionFilter = null,
        ?string $nodeTypeFilter = null
    ) {
        $this->workspace = $workspace;
        $this->seoPropertiesFilter = $seoPropertiesFilter;
        $this->focusKeywordPropertyFilter = $focusKeywordPropertyFilter;
        $this->languageDimensionFilter = $languageDimensionFilter;
        $this->nodeTypeFilter = empty($nodeTypeFilter) ? null : $nodeTypeFilter;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getSeoPropertiesFilter(): string
    {
        return $this->seoPropertiesFilter;
    }

    public function getFocusKeywordPropertyFilter(): string
    {
        return $this->focusKeywordPropertyFilter;
    }

    public function getLanguageDimensionFilter(): ?string
    {
        return $this->languageDimensionFilter;
    }

    public function getNodeTypeFilter(): ?string
    {
        return $this->nodeTypeFilter;
    }

    /**
     * @param array{
     *     workspace: string,
     *     seoPropertiesFilter: string,
     *     focusKeywordPropertyFilter: string,
     *     languageDimensionFilter: string|null,
     *     nodeTypeFilter: string|null
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['workspace'],
            $array['seoPropertiesFilter'],
            $array['focusKeywordPropertyFilter'],
            $array['languageDimensionFilter'] ?? null,
            $array['nodeTypeFilter'] ?? null
        );
    }
}

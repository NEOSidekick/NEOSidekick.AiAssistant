<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindDocumentNodesFilter implements LanguageDimensionFilterInterface
{
    /**
     * 'important-pages' | 'custom'
     * @var string
     */
    protected string $filter;

    /**
     * @var string
     */
    protected string $workspace;

    /**
     * 'none', 'only-empty-focus-keywords' | 'only-existing-focus-keywords'
     * @var string
     */
    protected string $seoPropertiesFilter;

    /**
     * 'none' | 'only-empty-alternative-text-or-title-text' | 'only-empty-alternative-text' | 'only-empty-title-text' | 'only-existing-alternative-text' | 'only-existing-title-text'
     * @var string
     */
    protected string $imagePropertiesFilter;

    /**
     * 'none' | 'only-empty-focus-keywords' | 'only-existing-focus-keywords'
     * @var string
     */
    protected string $focusKeywordPropertyFilter;

    /**
     * @var string[]
     */
    protected array $languageDimensionFilter = [];

    /**
     * @var string|null
     */
    protected ?string $nodeTypeFilter = null;

    /**
     * @var string|null
     */
    protected ?string $baseNodeTypeFilter = null;

    /**
     * @param string      $filter
     * @param string|null $workspace
     * @param string|null $seoPropertiesFilter
     * @param string|null $focusKeywordPropertyFilter
     * @param string|null $imagePropertiesFilter
     * @param string|null $languageDimensionFilter
     * @param string|null $nodeTypeFilter
     * @param string|null $baseNodeTypeFilter
     */
    public function __construct(
        string $filter,
        ?string $workspace,
        ?string $seoPropertiesFilter = 'none',
        ?string $focusKeywordPropertyFilter = 'none',
        ?string $imagePropertiesFilter = 'none',
        ?string $languageDimensionFilter = null,
        ?string $nodeTypeFilter = null,
        ?string $baseNodeTypeFilter = null
    ) {
        $this->filter = $filter;
        $this->workspace = $workspace;
        $this->seoPropertiesFilter = $seoPropertiesFilter;
        $this->imagePropertiesFilter = $imagePropertiesFilter;
        $this->focusKeywordPropertyFilter = $focusKeywordPropertyFilter;
        $this->languageDimensionFilter = $languageDimensionFilter ? explode(',', $languageDimensionFilter) : [];
        $this->nodeTypeFilter = empty($nodeTypeFilter) ? null : $nodeTypeFilter;
        $this->baseNodeTypeFilter = $baseNodeTypeFilter;
    }

    public function getFilter(): string
    {
        return $this->filter;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getSeoPropertiesFilter(): string
    {
        return $this->seoPropertiesFilter;
    }

    public function getImagePropertiesFilter(): string
    {
        return $this->imagePropertiesFilter;
    }

    public function getFocusKeywordPropertyFilter(): string
    {
        return $this->focusKeywordPropertyFilter;
    }

    public function getLanguageDimensionFilter(): ?array
    {
        return $this->languageDimensionFilter;
    }

    public function getNodeTypeFilter(): ?string
    {
        return $this->nodeTypeFilter;
    }

    public function getBaseNodeTypeFilter(): ?string
    {
        return $this->baseNodeTypeFilter;
    }

    /**
     * @param array{
     *     filter: string,
     *     workspace: string,
     *     seoPropertiesFilter: string,
     *     focusKeywordPropertyFilter: string,
     *     imagePropertiesFilter: string,
     *     languageDimensionFilter: string[],
     *     nodeTypeFilter: string|null,
     *     baseNodeTypeFilter: string|null
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['filter'],
            $array['workspace'],
            $array['seoPropertiesFilter'],
            $array['focusKeywordPropertyFilter'],
            $array['imagePropertiesFilter'],
            $array['languageDimensionFilter'] ?? [],
            $array['nodeTypeFilter'] ?? null,
            $array['baseNodeTypeFilter'] ?? null
        );
    }
}

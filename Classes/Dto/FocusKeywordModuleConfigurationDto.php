<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FocusKeywordModuleConfigurationDto
{
    private const DEFAULT_LIMIT = 5;
    /**
     * @var string
     */
    protected string $workspace;

    /**
     * 'only-empty', 'only-existing' or 'both'
     * @var string
     */
    protected string $mode;

    /**
     * @var bool
     */
    protected bool $generateEmptyFocusKeywords;

    /**
     * @var bool
     */
    protected bool $regenerateExistingFocusKeywords;

    /**
     * @var string|null
     */
    protected ?string $nodeTypeFilter = null;

    /**
     * @var int
     */
    protected int $limit = self::DEFAULT_LIMIT;

    /**
     * Defines how many items to skip before returning items
     *
     * @var int
     */
    protected int $firstResult = 0;

    /**
     * @param string      $workspace
     * @param string      $mode
     * @param bool        $generateEmptyFocusKeywords
     * @param bool        $regenerateExistingFocusKeywords
     * @param string|null $nodeTypeFilter
     * @param int         $limit
     * @param int         $firstResult
     */
    public function __construct(
        string $workspace,
        string $mode,
        bool $generateEmptyFocusKeywords,
        bool $regenerateExistingFocusKeywords,
        ?string $nodeTypeFilter,
        int $limit,
        int $firstResult
    ) {
        $this->workspace = $workspace;
        $this->mode = $mode;
        $this->generateEmptyFocusKeywords = $generateEmptyFocusKeywords;
        $this->regenerateExistingFocusKeywords = $regenerateExistingFocusKeywords;
        $this->nodeTypeFilter = $nodeTypeFilter;
        $this->limit = $limit;
        $this->firstResult = $firstResult;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function shouldGenerateEmptyFocusKeywords(): bool
    {
        return $this->generateEmptyFocusKeywords;
    }

    public function shouldRegenerateExistingFocusKeywords(): bool
    {
        return $this->regenerateExistingFocusKeywords;
    }

    public function getNodeTypeFilter(): ?string
    {
        return $this->nodeTypeFilter;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getFirstResult(): int
    {
        return $this->firstResult;
    }

    /**
     * @param array{
     *     workspace: string,
     *     mode: string,
     *     generateEmptyFocusKeywords: bool,
     *     regenerateExistingFocusKeywords: bool,
     *     nodeTypeFilter: string|null,
     *     limit: int,
     *     firstResult: int
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['workspace'],
            $array['mode'],
            $array['generateEmptyFocusKeywords'] ?? false,
            $array['regenerateExistingFocusKeywords'] ?? false,
            $array['nodeTypeFilter'] ?? null,
            $array['limit'] ?? self::DEFAULT_LIMIT,
            $array['firstResult'] ?? 0
        );
    }
}

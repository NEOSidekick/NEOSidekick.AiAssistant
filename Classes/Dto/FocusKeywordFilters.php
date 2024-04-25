<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FocusKeywordFilters
{
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
     * @var string|null
     */
    protected ?string $nodeTypeFilter = null;

    /**
     * @param string      $workspace
     * @param string      $mode
     * @param string|null $nodeTypeFilter
     */
    public function __construct(
        string $workspace,
        string $mode,
        ?string $nodeTypeFilter = null
    ) {
        $this->workspace = $workspace;
        $this->mode = $mode;
        $this->nodeTypeFilter = $nodeTypeFilter;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getNodeTypeFilter(): ?string
    {
        return $this->nodeTypeFilter;
    }

    /**
     * @param array{
     *     workspace: string,
     *     mode: string,
     *     nodeTypeFilter: string|null
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['workspace'],
            $array['mode'],
            $array['nodeTypeFilter'] ?? null
        );
    }
}

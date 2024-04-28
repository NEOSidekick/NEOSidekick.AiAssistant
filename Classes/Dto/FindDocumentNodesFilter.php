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
    protected string $propertyFilter;

    /**
     * @var string|null
     */
    protected ?string $nodeTypeFilter = null;

    /**
     * @param string      $workspace
     * @param string|null $propertyFilter
     * @param string|null $nodeTypeFilter
     */
    public function __construct(
        string  $workspace,
        ?string  $propertyFilter = 'none',
        ?string $nodeTypeFilter = null
    ) {
        $this->workspace = $workspace;
        $this->propertyFilter = $propertyFilter;
        $this->nodeTypeFilter = empty($nodeTypeFilter) ? null : $nodeTypeFilter;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getPropertyFilter(): string
    {
        return $this->propertyFilter;
    }

    public function getNodeTypeFilter(): ?string
    {
        return $this->nodeTypeFilter;
    }

    /**
     * @param array{
     *     workspace: string,
     *     propertyFilter: string,
     *     nodeTypeFilter: string|null
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['workspace'],
            $array['propertyFilter'],
            $array['nodeTypeFilter'] ?? null
        );
    }
}

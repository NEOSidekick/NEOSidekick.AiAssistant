<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @psalm-template TKey of array-key
 * @psalm-template T
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class PaginatedCollection implements JsonSerializable
{
    /**
     * @var array
     * @psalm-var array<TKey,T>
     */
    protected array $items;

    /**
     * @var int
     */
    protected int $nextFirstResult;

    /**
     * @param array $items
     * @psalm-param array<TKey,T> $items
     * @param int   $nextFirstResult
     */
    public function __construct(array $items, int $nextFirstResult)
    {
        $this->items = $items;
        $this->nextFirstResult = $nextFirstResult;
    }

    /**
     * @return array
     * @psalm-return array<TKey,T>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getNextFirstResult(): int
    {
        return $this->nextFirstResult;
    }

    public function jsonSerialize(): array
    {
        return [
            'items' => array_map(fn (JsonSerializable $item) => $item->jsonSerialize(), $this->items),
            'nextFirstResult' => $this->nextFirstResult
        ];
    }
}

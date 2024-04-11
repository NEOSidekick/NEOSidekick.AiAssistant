<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class ResultCollectionDto implements JsonSerializable
{
    /**
     * @var array
     */
    protected array $items;
    /**
     * @var int
     */
    protected int $nextFirstResult;

    /**
     * @param array $items
     * @param int   $nextFirstResult
     */
    public function __construct(array $items, int $nextFirstResult)
    {
        $this->items = $items;
        $this->nextFirstResult = $nextFirstResult;
    }

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

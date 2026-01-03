<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto;

use DateTimeImmutable;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * DTO representing the response structure for media asset search results.
 *
 * Contains metadata about the search query and a list of matching assets
 * with a total count for pagination awareness.
 *
 * @Flow\Proxy(false)
 */
final class MediaAssetSearchResult implements JsonSerializable
{
    /**
     * @param DateTimeImmutable $generatedAt Timestamp of response generation
     * @param string $query The search term used
     * @param string $mediaType The media type filter applied
     * @param array<MediaAssetData> $assets List of matching assets
     * @param int $totalCount Total number of matching assets (may exceed returned count)
     */
    public function __construct(
        private readonly DateTimeImmutable $generatedAt,
        private readonly string $query,
        private readonly string $mediaType,
        private readonly array $assets,
        private readonly int $totalCount
    ) {
    }

    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @return array<MediaAssetData>
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * @return array{generatedAt: string, query: string, mediaType: string, assets: array<MediaAssetData>, totalCount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'generatedAt' => $this->generatedAt->format('c'),
            'query' => $this->query,
            'mediaType' => $this->mediaType,
            'assets' => $this->assets,
            'totalCount' => $this->totalCount,
        ];
    }
}


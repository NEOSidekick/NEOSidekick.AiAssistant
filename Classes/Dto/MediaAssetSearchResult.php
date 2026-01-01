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
     * Create a MediaAssetSearchResult DTO containing search metadata and matching assets.
     *
     * All constructor parameters are stored as readonly properties.
     *
     * @param DateTimeImmutable $generatedAt Timestamp when the response was generated.
     * @param string $query The search term used.
     * @param string $mediaType The media type filter applied.
     * @param array<MediaAssetData> $assets Array of matching MediaAssetData entries.
     * @param int $totalCount Total number of matching assets (may exceed the returned assets count).
     */
    public function __construct(
        private readonly DateTimeImmutable $generatedAt,
        private readonly string $query,
        private readonly string $mediaType,
        private readonly array $assets,
        private readonly int $totalCount
    ) {
    }

    /**
     * Get the timestamp when the search result was generated.
     *
     * @return DateTimeImmutable The generation timestamp.
     */
    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /**
     * The search query used to produce these results.
     *
     * @return string The search query string.
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Gets the media type filter applied to the search.
     *
     * @return string The media type filter used for the search.
     */
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * Get the list of matching media assets.
     *
     * @return array<MediaAssetData> The list of matching media assets.
     */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /**
     * Total number of matching media assets for the search.
     *
     * @return int The total number of matching assets.
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }

    /**
     * Convert the search result into an associative array suitable for JSON encoding.
     *
     * The array contains the DTO fields; `generatedAt` is formatted as an ISO 8601 string.
     *
     * @return array{generatedAt: string, query: string, mediaType: string, assets: array<MediaAssetData>, totalCount: int} Associative array representing the search result; `generatedAt` is produced with DateTimeImmutable::format('c').
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

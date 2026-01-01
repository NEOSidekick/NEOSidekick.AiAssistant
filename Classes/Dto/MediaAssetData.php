<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * DTO representing a media asset with extended details for LLM consumption.
 *
 * This DTO provides all the information an LLM needs to intelligently select
 * an appropriate asset for content creation:
 * - identifier: UUID for use in patches
 * - filename: Original file name (often contains keywords)
 * - title: Editorial name given by content editors (most descriptive)
 * - caption: Description/alt text (provides context)
 * - mediaType: MIME type for filtering (e.g., image/png)
 * - tags: Categorization labels for semantic matching
 *
 * @Flow\Proxy(false)
 */
final class MediaAssetData implements JsonSerializable
{
    /**
     * Create a MediaAssetData DTO containing identifying, descriptive and classification details for a media asset.
     *
     * @param string $identifier Asset UUID used for updates and identification.
     * @param string $filename Original file name.
     * @param string $title Editorial title (may be empty).
     * @param string $caption Description or alt text for the asset (may be empty).
     * @param string $mediaType MIME type of the asset (e.g., "image/png").
     * @param array<string> $tags List of tag labels for categorization and semantic context.
     */
    public function __construct(
        private readonly string $identifier,
        private readonly string $filename,
        private readonly string $title,
        private readonly string $caption,
        private readonly string $mediaType,
        private readonly array $tags
    ) {
    }

    /**
     * Media asset identifier (UUID) used for update patches.
     *
     * @return string The asset UUID identifying the media asset for updates.
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Retrieve the original filename of the media asset.
     *
     * @return string The original filename.
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * Retrieve the asset's editorial title.
     *
     * @return string The editorial title of the media asset.
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * Retrieves the media asset's caption (description or alt text).
     *
     * @return string The caption text (description/alt text).
     */
    public function getCaption(): string
    {
        return $this->caption;
    }

    /**
     * Gets the media MIME type of the asset.
     *
     * @return string The asset's MIME type (for example, "image/png" or "video/mp4").
     */
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * Retrieve the tag labels associated with the media asset.
     *
     * @return array<string> The tag labels associated with the media asset.
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * Provides a structured representation of the media asset for JSON serialization.
     *
     * @return array{identifier: string, filename: string, title: string, caption: string, mediaType: string, tags: array<string>} Associative array containing the asset's identifier, filename, title, caption, MIME type, and tags.
     */
    public function jsonSerialize(): array
    {
        return [
            'identifier' => $this->identifier,
            'filename' => $this->filename,
            'title' => $this->title,
            'caption' => $this->caption,
            'mediaType' => $this->mediaType,
            'tags' => $this->tags,
        ];
    }
}

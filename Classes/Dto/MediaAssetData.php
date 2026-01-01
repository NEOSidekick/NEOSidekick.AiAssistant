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
     * @param string $identifier Asset UUID for use in update patches
     * @param string $filename Original file name
     * @param string $title Editorial title (may be empty)
     * @param string $caption Description/alt text (may be empty)
     * @param string $mediaType MIME type (e.g., "image/png")
     * @param array<string> $tags Tag labels for categorization
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

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCaption(): string
    {
        return $this->caption;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @return array<string>
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return array{identifier: string, filename: string, title: string, caption: string, mediaType: string, tags: array<string>}
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


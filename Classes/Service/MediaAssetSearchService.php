<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use DateTimeImmutable;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Doctrine\Query;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\Tag;
use NEOSidekick\AiAssistant\Dto\MediaAssetData;
use NEOSidekick\AiAssistant\Dto\MediaAssetSearchResult;

/**
 * Service for searching media assets optimized for LLM consumption.
 *
 * Wraps Neos AssetRepository search functionality with additional
 * filtering by media type and structured response formatting.
 *
 * @Flow\Scope("singleton")
 */
class MediaAssetSearchService
{
    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
         * Search assets by matching the given term against title, resource filename, and caption.
         *
         * The result set can be restricted by a wildcard media type (for example "image/*").
         * The provided $limit is clamped to the range 1–50 and the returned total count reflects
         * the number of matching assets before the limit is applied.
         *
         * @param string $searchTerm The term to search for in title, resource filename, and caption.
         * @param string $mediaTypeFilter Media type filter using wildcards (e.g., "image/*", "application/pdf"). Use an empty string or "*/*" to skip media type filtering.
         * @param int $limit Maximum number of results to return; values are clamped to the range 1–50.
         * @return MediaAssetSearchResult A result object containing the timestamp, original search term, applied media type filter, an array of MediaAssetData DTOs, and the total count of matches before limiting.
         */
    public function search(
        string $searchTerm,
        string $mediaTypeFilter = 'image/*',
        int $limit = 10
    ): MediaAssetSearchResult {
        // Clamp limit to valid range
        $limit = max(1, min(50, $limit));

        // Build the query matching Neos AssetRepository::findBySearchTermOrTags pattern
        /** @var Query $query */
        $query = $this->persistenceManager->createQueryForType(Asset::class);

        // Search in title, filename, and caption (case-insensitive)
        $searchConstraints = [
            $query->like('title', '%' . $searchTerm . '%', false),
            $query->like('resource.filename', '%' . $searchTerm . '%', false),
            $query->like('caption', '%' . $searchTerm . '%', false),
        ];
        $query->matching($query->logicalOr($searchConstraints));

        // Filter out asset variants (same as AssetRepository)
        $this->addAssetVariantToQueryConstraints($query);

        // Apply media type filter if specified
        if ($mediaTypeFilter !== '' && $mediaTypeFilter !== '*/*') {
            $this->addMediaTypeToQueryConstraints($query, $mediaTypeFilter);
        }

        // Order by last modified (most recent first)
        $query->setOrderings(['lastModified' => QueryInterface::ORDER_DESCENDING]);

        // Get total count before limiting
        $totalCount = $query->count();

        // Apply limit and execute
        $query->setLimit($limit);
        $assets = $query->execute()->toArray();

        // Transform to DTOs
        $assetDtos = array_map(
            fn(Asset $asset) => $this->transformToDto($asset),
            $assets
        );

        return new MediaAssetSearchResult(
            new DateTimeImmutable(),
            $searchTerm,
            $mediaTypeFilter,
            $assetDtos,
            $totalCount
        );
    }

    /**
     * Convert an Asset entity into a MediaAssetData DTO.
     *
     * Collects the asset's tag labels and maps identifier, filename, title, caption, media type, and tags into the DTO.
     * If the asset's resource is missing or deleted, filename and mediaType are returned as empty strings.
     *
     * @param Asset $asset The asset entity to convert.
     * @return MediaAssetData The resulting MediaAssetData DTO containing identifier, filename, title, caption, mediaType, and tags.
     */
    private function transformToDto(Asset $asset): MediaAssetData
    {
        // Extract tag labels
        $tags = [];
        /** @var Tag $tag */
        foreach ($asset->getTags() as $tag) {
            $tags[] = $tag->getLabel();
        }

        // Guard against assets with missing or deleted resources
        $resource = $asset->getResource();
        $filename = $resource !== null ? $resource->getFilename() : '';
        $mediaType = $resource !== null ? $asset->getMediaType() : '';

        return new MediaAssetData(
            identifier: $asset->getIdentifier(),
            filename: $filename,
            title: $asset->getTitle() ?? '',
            caption: $asset->getCaption() ?? '',
            mediaType: $mediaType,
            tags: $tags
        );
    }

    /**
     * Add a media-type constraint to the query, supporting wildcard filters (e.g., "image/*").
     *
     * Converts a wildcard pattern by replacing `*` with `%` and constrains `resource.mediaType`
     * to match that pattern, merging the constraint with the query's existing constraints using logical AND.
     *
     * @param \Neos\Flow\Persistence\QueryInterface $query The query to which the media-type constraint will be added.
     * @param string $mediaTypeFilter A media type filter, possibly containing a `*` wildcard (for example "image/*").
     */
    private function addMediaTypeToQueryConstraints(Query $query, string $mediaTypeFilter): void
    {
        $constraints = $query->getConstraint();

        // Convert wildcard format (image/*) to SQL LIKE pattern (image/%)
        $pattern = str_replace('*', '%', $mediaTypeFilter);

        $mediaTypeConstraint = $query->like('resource.mediaType', $pattern, false);
        $query->matching($query->logicalAnd([$constraints, $mediaTypeConstraint]));
    }

    /**
     * Excludes asset variant entities from the given query's results.
     *
     * Adds `NOT INSTANCE OF` constraints for every class that implements
     * AssetVariantInterface and is annotated as an entity, and merges those
     * constraints with the query's existing constraint using logical AND.
     *
     * Non-entity implementations are skipped to avoid schema-related errors.
     *
     * @param Query $query The query to modify by adding variant-exclusion constraints.
     */
    private function addAssetVariantToQueryConstraints(Query $query): void
    {
        $variantsConstraints = [];
        $variantClassNames = $this->reflectionService->getAllImplementationClassNamesForInterface(AssetVariantInterface::class);

        foreach ($variantClassNames as $variantClassName) {
            if (!$this->reflectionService->isClassAnnotatedWith($variantClassName, \Neos\Flow\Annotations\Entity::class)) {
                // Ignore non-entity classes to prevent "class schema found" error
                continue;
            }
            $variantsConstraints[] = 'e NOT INSTANCE OF ' . $variantClassName;
        }

        $constraints = $query->getConstraint();
        $query->matching($query->logicalAnd([$constraints, $query->logicalAnd($variantsConstraints)]));
    }
}

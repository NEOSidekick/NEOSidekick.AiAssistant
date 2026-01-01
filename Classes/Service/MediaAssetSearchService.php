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
     * Search for assets matching a query string.
     *
     * Searches across title, filename, and caption fields.
     * Results can be filtered by media type (e.g., "image/*").
     *
     * @param string $searchTerm The search term to match
     * @param string $mediaTypeFilter Filter by media type (e.g., "image/*", "application/pdf")
     * @param int $limit Maximum number of results to return (1-50)
     * @return MediaAssetSearchResult Search results with assets and total count
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
     * Transform an Asset entity to a MediaAssetData DTO.
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
     * Add media type filter to query constraints.
     *
     * Supports wildcards like "image/*" which matches "image/png", "image/jpeg", etc.
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
     * Filter out asset variants from query results.
     *
     * Taken from Neos\Media\Domain\Repository\AssetRepository to exclude
     * image variants and other derived assets from search results.
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


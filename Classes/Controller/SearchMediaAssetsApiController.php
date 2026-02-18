<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
use NEOSidekick\AiAssistant\Service\MediaAssetSearchService;

/**
 * API controller to search media assets in the Neos Media library.
 *
 * Provides search functionality optimized for LLM agents to find
 * appropriate images and media files for content creation.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class SearchMediaAssetsApiController extends ActionController
{
    use ApiAuthenticationTrait;
    /**
     * @Flow\Inject
     * @var MediaAssetSearchService
     */
    protected $searchService;

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * Initialize action - set JSON content type.
     */
    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * Search for media assets by text query.
     *
     * Searches across asset title, filename, and caption fields.
     * If query is empty or "*", all assets are returned (respecting mediaType and limit).
     * Returns extended asset details optimized for LLM consumption.
     *
     * @param string $query The search term (empty or "*" returns all assets)
     * @param string $mediaType Filter by media type pattern (default: "image/*")
     * @param int $limit Maximum results to return (default: 10, max: 50)
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function searchAction(
        string $query = '',
        string $mediaType = 'image/*',
        int $limit = 10
    ): string {
        // Validate Bearer token authentication
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        // Empty query and wildcard query should return all assets.
        $normalizedQuery = trim($query);
        if ($normalizedQuery === '*') {
            $normalizedQuery = '';
        }

        try {
            $result = $this->searchService->search(
                searchTerm: $normalizedQuery,
                mediaTypeFilter: $mediaType,
                limit: $limit
            );

            return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
    }

}


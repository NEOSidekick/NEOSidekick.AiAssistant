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
     * Set the HTTP response Content-Type header to `application/json` for this controller's actions.
     */
    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * Perform a text search for media assets and return the results as a JSON string.
     *
     * Validates Bearer authentication and the required $query parameter; searches title, filename, and caption fields and applies a media type filter and result limit.
     *
     * @param string $query The search term to match against asset title, filename, or caption.
     * @param string $mediaType Media type pattern to filter results (e.g., "image/*").
     * @param int $limit Maximum number of results to return (default 10, enforced maximum 50).
     * @return string JSON-encoded search results on success, or a JSON-encoded error object with `error` and `message` keys on failure.
     * @throws JsonException If JSON encoding fails.
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

        // Validate required query parameter
        if (empty(trim($query))) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'The "query" parameter is required and cannot be empty'
            ], JSON_THROW_ON_ERROR);
        }

        try {
            $result = $this->searchService->search(
                searchTerm: $query,
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

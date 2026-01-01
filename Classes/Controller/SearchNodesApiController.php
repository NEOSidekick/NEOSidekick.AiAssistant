<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
use NEOSidekick\AiAssistant\Service\SearchNodesExtractor;

/**
 * API controller to search nodes across the content repository.
 *
 * Provides grep-like search functionality across all node properties
 * for a given workspace and dimension configuration. Used by LLM agents
 * to find specific content.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class SearchNodesApiController extends ActionController
{
    use ApiAuthenticationTrait;
    /**
     * @Flow\Inject
     * @var SearchNodesExtractor
     */
    protected $extractor;

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
     * Search nodes by property values.
     *
     * Performs a case-insensitive search across all node properties,
     * similar to grep functionality. Returns matching nodes with their
     * properties and parent document context.
     *
     * @param string $query The search term (required)
     * @param string $workspace The workspace name (default: 'live')
     * @param string $dimensions JSON-encoded dimensions array
     * @param string $nodeTypeFilter Filter by NodeType (e.g., 'Neos.Neos:Content')
     * @param string $pathStartingPoint Limit search to nodes under this path
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function searchAction(
        string $query = '',
        string $workspace = 'live',
        string $dimensions = '{}',
        string $nodeTypeFilter = '',
        string $pathStartingPoint = ''
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

        // Parse dimensions from JSON string
        $dimensionsArray = json_decode($dimensions, true);
        if (!is_array($dimensionsArray)) {
            $dimensionsArray = [];
        }

        try {
            $result = $this->extractor->search(
                query: $query,
                workspace: $workspace,
                dimensions: $dimensionsArray,
                nodeTypeFilter: $nodeTypeFilter !== '' ? $nodeTypeFilter : null,
                pathStartingPoint: $pathStartingPoint !== '' ? $pathStartingPoint : null
            );

            return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
    }

}

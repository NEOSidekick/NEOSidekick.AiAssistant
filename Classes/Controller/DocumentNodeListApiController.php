<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;

/**
 * API controller to expose document node list for external consumption.
 *
 * Returns a list of all document nodes (pages) for a given workspace
 * and dimension configuration. Used by LLM agents to discover pages.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class DocumentNodeListApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var DocumentNodeListExtractor
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
     * Get all document nodes as JSON.
     *
     * @param string $workspace The workspace name (default: 'live')
     * @param string $dimensions JSON-encoded dimensions array
     * @param string $site Site node name (optional, defaults to first site)
     * @param string $nodeTypeFilter Filter by NodeType (default: all documents)
     * @param int $depth Maximum traversal depth (-1 = unlimited)
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function listAction(
        string $workspace = 'live',
        string $dimensions = '{}',
        string $site = '',
        string $nodeTypeFilter = 'Neos.Neos:Document',
        int $depth = -1
    ): string {
        // Validate Bearer token authentication
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        // Parse dimensions from JSON string
        $dimensionsArray = json_decode($dimensions, true);
        if (!is_array($dimensionsArray)) {
            $dimensionsArray = [];
        }

        try {
            $result = $this->extractor->extract(
                workspace: $workspace,
                dimensions: $dimensionsArray,
                siteNodeName: $site !== '' ? $site : null,
                nodeTypeFilter: $nodeTypeFilter,
                depth: $depth
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

    /**
     * Validate Bearer token authentication.
     *
     * @return string|null Error response JSON if authentication fails, null if valid
     * @throws JsonException
     */
    protected function validateAuthentication(): ?string
    {
        $authHeader = $this->request->getHttpRequest()->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Missing Authorization header'
            ], JSON_THROW_ON_ERROR);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Invalid Authorization header format, expected Bearer token'
            ], JSON_THROW_ON_ERROR);
        }

        $token = substr($authHeader, 7);
        if ($token !== $this->apiKey) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key'
            ], JSON_THROW_ON_ERROR);
        }

        return null;
    }
}


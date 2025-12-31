<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\NodeTreeExtractor;

/**
 * API controller to expose node tree data for external consumption.
 *
 * This controller provides a JSON API endpoint that returns raw node tree
 * data starting from a given node. The NEOSidekick SaaS calls this endpoint
 * to fetch the tree and transform it into JSX for LLM consumption.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class NodeTreeSchemaApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var NodeTreeExtractor
     */
    protected $treeExtractor;

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
     * Get the node tree as JSON starting from a given node.
     *
     * This endpoint returns the complete node tree with all properties
     * and children. The data is structured using the unified children model
     * (_self for ContentCollections, named slots for childNodes).
     *
     * @param string $nodeId The node identifier (UUID) to start from
     * @param string $workspace The workspace name (default: 'live')
     * @param string $dimensions JSON-encoded dimensions array
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function getNodeTreeAction(string $nodeId, string $workspace = 'live', string $dimensions = '{}'): string
    {
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
            $tree = $this->treeExtractor->extract($nodeId, $workspace, $dimensionsArray);
            return json_encode($tree, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            $this->response->setStatusCode(404);
            return json_encode([
                'error' => 'Not Found',
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

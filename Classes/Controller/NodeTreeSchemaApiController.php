<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
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
    use ApiAuthenticationTrait;
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
     * Prepare the HTTP response for JSON output by setting its Content-Type header.
     */
    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * Get the node tree as a JSON string starting from the specified node.
     *
     * The JSON contains the complete node tree including properties and children
     * (using the unified children model: `_self` for ContentCollections and named slots for childNodes).
     *
     * @param string $nodeId The node identifier (UUID) to start from.
     * @param string $workspace The workspace name (defaults to "live").
     * @param string $dimensions JSON-encoded dimensions object; invalid or non-object input will be treated as an empty array.
     * @return string JSON string containing the node tree or an error object with `error` and `message` keys.
     * @throws JsonException If JSON encoding of the result or error payload fails.
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
        } catch (\InvalidArgumentException $e) {
            $this->response->setStatusCode(404);
            return json_encode([
                'error' => 'Not Found',
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => 'An unexpected error occurred'
            ], JSON_THROW_ON_ERROR);
        }
    }

}
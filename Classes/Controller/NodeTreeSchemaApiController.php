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
 * Authentication is done via JWT Bearer token (Flow security provider).
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

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
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
    use ApiAuthenticationTrait;
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
     * Produce a JSON representation of document nodes for the specified workspace and dimension configuration.
     *
     * Decodes the provided dimensions JSON string and returns the extractor's result as a JSON string.
     * If authentication fails the method returns the authentication error payload; on extraction errors the response status is set to 400 and an error payload is returned.
     *
     * @param string $workspace The workspace name to query (e.g., "live").
     * @param string $dimensions JSON-encoded dimensions map (e.g., '{"language":"en"}').
     * @param string $site Optional site node name; when empty the first site is used.
     * @param string $nodeTypeFilter NodeType filter to apply (e.g., "Neos.Neos:Document").
     * @param int $depth Maximum traversal depth; use -1 for unlimited.
     * @return string JSON containing the document nodes on success, or an authentication/error payload on failure.
     * @throws JsonException When encoding the response fails.
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

}
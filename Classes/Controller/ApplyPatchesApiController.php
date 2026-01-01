<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\NodePatchService;

/**
 * API controller to apply atomic patches to nodes.
 *
 * Processes LLM-generated node operations (create, update, move, delete)
 * with validation, transaction-based rollback, and dry-run support.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class ApplyPatchesApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var NodePatchService
     */
    protected $patchService;

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
     * Apply patches to the content repository.
     *
     * Accepts a JSON body with the following structure:
     * {
     *   "workspace": "user-admin",
     *   "dimensions": {"language": ["de"]},
     *   "dryRun": false,
     *   "patches": [
     *     { "operation": "createNode", "parentNodeId": "uuid", "nodeType": "...", "position": "into", "properties": {...} },
     *     { "operation": "updateNode", "nodeId": "uuid", "properties": {...} },
     *     { "operation": "moveNode", "nodeId": "uuid", "targetNodeId": "uuid", "position": "after" },
     *     { "operation": "deleteNode", "nodeId": "uuid" }
     *   ]
     * }
     *
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function applyPatchesAction(): string
    {
        // Validate Bearer token authentication
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        // Parse JSON body from request
        // Use php://input as the PSR-7 stream may have already been consumed
        $requestBody = file_get_contents('php://input');
        $data = json_decode($requestBody, true);

        if (!is_array($data)) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Invalid JSON body'
            ], JSON_THROW_ON_ERROR);
        }

        // Validate required fields
        $validationError = $this->validateRequestData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        // Extract parameters with defaults
        $workspace = $data['workspace'] ?? 'live';
        $dimensions = $data['dimensions'] ?? [];
        $dryRun = $data['dryRun'] ?? false;
        $patches = $data['patches'];

        // Apply the patches
        $result = $this->patchService->applyPatches(
            $patches,
            $workspace,
            $dimensions,
            (bool)$dryRun
        );

        // Set appropriate HTTP status code
        if (!$result->isSuccess()) {
            $this->response->setStatusCode(422);
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Validate the request data structure.
     *
     * @param array<string, mixed> $data
     * @return string|null Error response JSON if validation fails, null if valid
     * @throws JsonException
     */
    protected function validateRequestData(array $data): ?string
    {
        // Validate patches array exists
        if (!isset($data['patches'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Missing required field "patches"'
            ], JSON_THROW_ON_ERROR);
        }

        if (!is_array($data['patches'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "patches" must be an array'
            ], JSON_THROW_ON_ERROR);
        }

        if (empty($data['patches'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "patches" cannot be empty'
            ], JSON_THROW_ON_ERROR);
        }

        // Validate each patch has an operation field
        foreach ($data['patches'] as $index => $patch) {
            if (!is_array($patch)) {
                $this->response->setStatusCode(400);
                return json_encode([
                    'error' => 'Bad Request',
                    'message' => sprintf('Patch at index %d must be an object', $index)
                ], JSON_THROW_ON_ERROR);
            }

            if (!isset($patch['operation'])) {
                $this->response->setStatusCode(400);
                return json_encode([
                    'error' => 'Bad Request',
                    'message' => sprintf('Patch at index %d is missing "operation" field', $index)
                ], JSON_THROW_ON_ERROR);
            }
        }

        // Validate workspace if provided
        if (isset($data['workspace']) && !is_string($data['workspace'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "workspace" must be a string'
            ], JSON_THROW_ON_ERROR);
        }

        // Validate dimensions if provided
        if (isset($data['dimensions']) && !is_array($data['dimensions'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "dimensions" must be an object'
            ], JSON_THROW_ON_ERROR);
        }

        return null;
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

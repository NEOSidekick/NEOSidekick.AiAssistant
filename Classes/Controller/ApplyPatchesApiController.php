<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Dto\Patch\PatchError;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use Neos\Neos\Service\UserService;

/**
 * API controller to apply atomic patches to nodes.
 *
 * Processes LLM-generated node operations (create, update, move, delete)
 * with validation and transaction-based rollback.
 *
 * Authentication is done via JWT Bearer token (Flow security provider).
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
     * @Flow\Inject
     * @var UserService
     */
    protected UserService $userService;

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
     *   "dimensions": {"language": ["de"]},
     *   "patches": [
     *     { "operation": "createNode", "positionRelativeToNodeId": "uuid", "nodeType": "...", "position": "into", "properties": {...} },
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
        // Parse JSON body from request
        // Use php://input as the PSR-7 stream may have already been consumed
        $requestBody = file_get_contents('php://input');

        try {
            $data = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Invalid JSON: ' . $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }

        if (!is_array($data)) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Request body must be a JSON object'
            ], JSON_THROW_ON_ERROR);
        }

        // Validate required fields
        $validationError = $this->validateRequestData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        // Resolve workspace from authenticated user
        $workspace = $this->userService->getPersonalWorkspaceName();
        if ($workspace === null) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Could not determine user workspace. No authenticated user found.'
            ], JSON_THROW_ON_ERROR);
        }

        // Extract parameters with defaults
        $dimensions = $data['dimensions'] ?? [];
        $patches = $data['patches'];

        // Apply the patches
        $result = $this->patchService->applyPatches(
            $patches,
            $workspace,
            $dimensions
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

        // Validate dimensions if provided
        if (isset($data['dimensions']) && !is_array($data['dimensions'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "dimensions" must be an object'
            ], JSON_THROW_ON_ERROR);
        }

        // Validate each dimension value is an array (e.g. {"language": ["de"]}, not {"language": "de"})
        // This prevents TypeError in NodePatchService::createContext() where reset() is called on values
        if (isset($data['dimensions']) && is_array($data['dimensions'])) {
            foreach ($data['dimensions'] as $dimensionName => $dimensionValues) {
                if (!is_array($dimensionValues)) {
                    $this->response->setStatusCode(400);
                    $example = is_scalar($dimensionValues) ? (string)$dimensionValues : 'value';
                    return json_encode([
                        'error' => 'Bad Request',
                        'message' => sprintf(
                            'Dimension "%s" value must be an array (e.g. ["%s"]), got %s',
                            $dimensionName,
                            $example,
                            gettype($dimensionValues)
                        )
                    ], JSON_THROW_ON_ERROR);
                }
            }
        }

        // dryRun was removed in 3.1.0. A truthy value must never silently write, so it is refused
        // with the failure body shape; false or absent is ignored.
        if (!empty($data['dryRun'])) {
            $this->response->setStatusCode(422);
            return json_encode(
                PatchResult::failure(
                    new PatchError('dry-run is no longer supported; apply the batch, a failure writes nothing', 0, 'batch'),
                    false
                ),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        }

        return null;
    }
}

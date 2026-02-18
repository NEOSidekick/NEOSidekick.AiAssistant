<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
use NEOSidekick\AiAssistant\Service\MediaAssetUploadService;

/**
 * API controller to upload media assets from remote URLs.
 *
 * Uses the same "upload" action naming that Neos.Media.Browser uses internally.
 *
 * Authentication is done via Bearer token matching the configured API key.
 *
 * @noinspection PhpUnused
 */
class UploadMediaAssetApiController extends ActionController
{
    use ApiAuthenticationTrait;

    /**
     * @Flow\Inject
     * @var MediaAssetUploadService
     */
    protected $uploadService;

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
     * Upload a media asset from URL.
     *
     * Request body:
     * {
     *   "url": "https://example.com/image.jpg",
     *   "title": "Optional title",
     *   "caption": "Optional caption"
     * }
     *
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function uploadAction(): string
    {
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        $requestBody = file_get_contents('php://input');

        try {
            $data = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Invalid JSON: ' . $exception->getMessage()
            ], JSON_THROW_ON_ERROR);
        }

        if (!is_array($data)) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Request body must be a JSON object'
            ], JSON_THROW_ON_ERROR);
        }

        $validationError = $this->validateRequestData($data);
        if ($validationError !== null) {
            return $validationError;
        }

        try {
            $result = $this->uploadService->uploadFromUrl(
                sourceUrl: trim((string)$data['url']),
                title: array_key_exists('title', $data) ? (string)$data['title'] : null,
                caption: array_key_exists('caption', $data) ? (string)$data['caption'] : null
            );

            $this->response->setStatusCode($result['created'] ? 201 : 200);

            return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\InvalidArgumentException $exception) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => $exception->getMessage()
            ], JSON_THROW_ON_ERROR);
        } catch (\RuntimeException $exception) {
            $statusCode = (int)$exception->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            $this->response->setStatusCode($statusCode);
            return json_encode([
                'error' => $statusCode === 502
                    ? 'Upstream Error'
                    : ($statusCode >= 500 ? 'Internal Server Error' : 'Bad Request'),
                'message' => $exception->getMessage()
            ], JSON_THROW_ON_ERROR);
        } catch (\Exception $exception) {
            $this->response->setStatusCode(500);
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => $exception->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return string|null
     * @throws JsonException
     */
    protected function validateRequestData(array $data): ?string
    {
        if (!isset($data['url']) || !is_string($data['url']) || trim($data['url']) === '') {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Missing required field "url"'
            ], JSON_THROW_ON_ERROR);
        }

        if (array_key_exists('title', $data) && !is_string($data['title'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "title" must be a string'
            ], JSON_THROW_ON_ERROR);
        }

        if (array_key_exists('caption', $data) && !is_string($data['caption'])) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'Field "caption" must be a string'
            ], JSON_THROW_ON_ERROR);
        }

        return null;
    }
}

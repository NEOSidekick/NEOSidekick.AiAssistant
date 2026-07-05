<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Service\PreviewTokenService;

/**
 * JWT-protected endpoint that returns a signed, short-lived preview URL
 * for a document node in the authenticated user's workspace.
 *
 * The returned URL points to the public PreviewRenderController and can be
 * opened by an external screenshot service (e.g. Browserless) without any
 * further authentication.
 *
 * Authentication is done via JWT Bearer token (Flow security provider).
 *
 * @noinspection PhpUnused
 */
class GetPreviewApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected UserService $userService;

    /**
     * @Flow\Inject
     * @var PreviewTokenService
     */
    protected $previewTokenService;

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
     * Generate a signed preview URL for the given node.
     *
     * @param string $nodeId Node aggregate identifier of the document node (required)
     * @param string $workspace The workspace name (default: authenticated user's personal workspace)
     * @param string $dimensions JSON-encoded dimensions array, e.g. {"language":["de"]}
     * @return string JSON response with previewUrl, previewPath and expiresAt
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function indexAction(string $nodeId = '', string $workspace = '', string $dimensions = '{}'): string
    {
        if ($this->securityContext->getAccount() === null) {
            $this->response->setStatusCode(401);
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Authentication required.',
            ], JSON_THROW_ON_ERROR);
        }

        if ($nodeId === '') {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'The parameter "nodeId" is required.',
            ], JSON_THROW_ON_ERROR);
        }

        $dimensionsArray = json_decode($dimensions, true);
        if (!is_array($dimensionsArray)) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => 'The parameter "dimensions" must be a valid JSON object.',
            ], JSON_THROW_ON_ERROR);
        }

        $workspace = $this->resolveWorkspace($workspace);
        $dimensionsJson = json_encode($dimensionsArray, JSON_THROW_ON_ERROR);

        $tokenData = $this->previewTokenService->generatePreviewPath($nodeId, $workspace, $dimensionsJson);

        return json_encode([
            'previewUrl' => $this->getBaseUriFromCurrentRequest() . $tokenData['previewPath'],
            'previewPath' => $tokenData['previewPath'],
            'expiresAt' => $tokenData['expiresAt']->format(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Same fallback logic as DocumentNodeListApiController::resolveWorkspace():
     * default to the authenticated user's personal workspace.
     */
    protected function resolveWorkspace(string $requestedWorkspace): string
    {
        $personalWorkspace = $this->userService->getPersonalWorkspaceName();
        if ($personalWorkspace !== null && ($requestedWorkspace === '' || $requestedWorkspace === 'live')) {
            return $personalWorkspace;
        }

        return $requestedWorkspace !== '' ? $requestedWorkspace : 'live';
    }

    /**
     * Build "scheme://host[:port]" from the current HTTP request. The caller
     * may still prefer previewPath and prepend its own public domain.
     */
    protected function getBaseUriFromCurrentRequest(): string
    {
        $uri = $this->request->getHttpRequest()->getUri();
        $baseUri = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $baseUri .= ':' . $uri->getPort();
        }

        return $baseUri;
    }
}

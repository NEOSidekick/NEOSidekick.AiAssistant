<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentTokenService;

/**
 * On-demand embed-token issuance for the chat iframe's parent frame.
 *
 * POST neosidekick/api/agentic/embed-token, answering {"embed_token": "<jwt>"}.
 *
 * Backend-session only: the controller is listed in the Neos.Neos:Backend
 * provider's request pattern (Settings.Internal.yaml) and in the
 * NEOSidekick.AiAssistant:CanUse privilege target (Policy.yaml), so exactly an
 * authenticated Neos backend session may call it - the minted token's identity
 * comes from that session's security context, never from request input.
 *
 * The Neos UI plugin fetches a token here at iframe-src construction time and
 * again for every postMessage re-issuance request from the embedded assistant;
 * the token gates the Laravel bootstrap's authBindingToken release.
 */
class AgentEmbedTokenApiController extends ActionController
{
    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected AgentTokenService $agentTokenService;

    /**
     * Mints a fresh embed token for the current backend user.
     *
     * Deliberately a POST even though nothing changes server-side: Flow's
     * SecurityEntryPointMiddleware captures only safe GETs as the intercepted request
     * for the after-login redirect, so a GET here - hit by the parent-frame JS while
     * the backend session is dead - would poison the editor's next login into landing
     * on this JSON blob. The POST avoids that capture entirely.
     *
     * CSRF protection is skipped deliberately: the
     * response is only ever issued to the live session holder making the request - it
     * derives the identity claim from that session, changes no state, and is marked
     * non-cacheable below.
     *
     * @Flow\SkipCsrfProtection
     */
    public function issueAction(): string
    {
        $this->response->setContentType('application/json');
        $this->response->setHttpHeader('Cache-Control', 'no-store');

        try {
            $embedToken = $this->agentTokenService->generateEmbedToken();
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());

            return json_encode([
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode(['embed_token' => $embedToken], JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use stdClass;
use Throwable;

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
 *
 * The handshake is also a push path, but only when the request carries a JSON
 * body: the plugin's plain first-load fetch (no body) never pushes, while the
 * assistant's gate handshake always posts {"forcePush": <bool>} - false on its
 * automatic attempt, which pushes the signing key when that is due (an
 * environment switch, a lost row), true on "Try again", which pushes even while
 * the plugin's record looks fresh. Either way the key is announced before the
 * token that references it goes out.
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
     * @Flow\Inject
     * @var AgentSigningKeyPushService
     */
    protected AgentSigningKeyPushService $agentSigningKeyPushService;

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
     * non-cacheable below. The one request input it reads, the push flag, is gated on
     * the application/json content type, which a cross-site simple request cannot
     * carry without a CORS preflight.
     *
     * @Flow\SkipCsrfProtection
     */
    public function issueAction(): string
    {
        $this->response->setContentType('application/json');
        $this->response->setHttpHeader('Cache-Control', 'no-store');

        $forcePush = $this->readForcePushFlag();
        if ($forcePush !== null) {
            $this->pushSigningKeyOnce($forcePush);
        }

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

    /**
     * Announces the public signing key before the token signed with it goes out, so the
     * receiving side already knows the key the token references.
     *
     * Fire-and-forget, exactly like the authorization flow's push: a NEOSidekick outage or a
     * broken push degrades to a mint without it, never to a missing token.
     *
     * Protected, not private: the CanUse privilege's within() matcher advises every method of
     * this controller, and Flow's AOP proxy cannot intercept a private one.
     */
    protected function pushSigningKeyOnce(bool $forcePush): void
    {
        try {
            $this->agentSigningKeyPushService->pushIfNecessary(force: $forcePush);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'NEOSidekick agent signing key push failed during embed token issuance: ' . $throwable->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * Null when the request carries no push instruction at all - no application/json media type
     * or no parsable JSON object body - in which case nothing is pushed. Otherwise true exactly
     * for a JSON object with `forcePush: true` (the boolean), false for any other JSON object.
     */
    protected function readForcePushFlag(): ?bool
    {
        $httpRequest = $this->request->getHttpRequest();
        $mediaType = strtolower(trim(explode(';', $httpRequest->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType !== 'application/json') {
            return null;
        }

        try {
            $decodedBody = json_decode((string)$httpRequest->getBody(), false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }
        if (!$decodedBody instanceof stdClass) {
            return null;
        }

        return ($decodedBody->forcePush ?? null) === true;
    }
}

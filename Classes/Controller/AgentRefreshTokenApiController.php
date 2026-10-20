<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Exception\AgentRefreshTokenRejectedException;
use NEOSidekick\AiAssistant\Security\Authentication\EntryPoint\JwtEntryPoint;
use NEOSidekick\AiAssistant\Service\AgentEndpointThrottle;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;

/**
 * Server-to-server refresh endpoint for new-generation agent JWTs.
 *
 * POST neosidekick/api/agentic/refresh-token with JSON body {"refresh_token": "<opaque>"}.
 * The refresh token IS the credential - the endpoint carries no other authentication
 * (public via Policy.yaml, like the token-protected PreviewRenderController).
 *
 * Response contract (the Laravel renewal client is built against it exactly):
 *
 *  - 200: {"jwt": ..., "refresh_token": <rotated successor>, "refresh_token_expires_at": <unix ts>}
 *  - 401 + NEOS_REFRESH_TOKEN_REJECTED marker (body field `errorCode` AND the
 *    X-NEOSidekick-Error-Code header, exact/case-sensitive, mirroring NEOS_JWT_REJECTED):
 *    only for POSITIVELY identified rejections of a token that was actually evaluated -
 *    unknown/revoked/spent/expired token, expired family, missing/inactive account.
 *  - 429 without the marker: a REJECTION from a client address that has spent its rejection
 *    budget on this endpoint ({@see AgentEndpointThrottle}); Retry-After names
 *    the window. The credential is evaluated first, so a valid token is always answered with
 *    200 no matter how much budget the address burned. Like every marker-less answer the 429
 *    is inconclusive by contract, so the caller retries.
 *  - 400 without the marker: the request carried no syntactically well-formed refresh
 *    token at all (no body, no JSON, no `refresh_token` string, wrong shape), so nothing
 *    was ever looked up and the answer says nothing about the credential Laravel holds.
 *  - Anything else (uncaught internal errors -> 500) is deliberately marker-less too: the
 *    Laravel side treats every marker-less answer as inconclusive and leaves the refresh
 *    token untouched.
 */
class AgentRefreshTokenApiController extends ActionController
{
    /**
     * Machine-readable marker for a positively rejected refresh token. Cross-repo
     * contract: the Laravel side matches on this exact value, in the same body-field/
     * header convention as {@see JwtEntryPoint::ERROR_CODE}.
     */
    public const ERROR_CODE = 'NEOS_REFRESH_TOKEN_REJECTED';

    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * @Flow\Inject
     * @var AgentRefreshTokenService
     */
    protected AgentRefreshTokenService $agentRefreshTokenService;

    /**
     * @Flow\Inject
     * @var AgentEndpointThrottle
     */
    protected AgentEndpointThrottle $agentEndpointThrottle;

    /**
     * Redeems an opaque refresh token for a fresh JWT plus a rotated successor token.
     *
     * The body is read straight off the HTTP request instead of through action arguments,
     * so a missing or malformed body deterministically takes the marker path instead of
     * whatever Flow's property mapping would raise for it.
     *
     * The credential is evaluated BEFORE the rejection budget is consulted: a successful
     * redeem is a 200 whatever the address' budget looks like, and only a rejection is
     * counted and - once the address is over budget - answered with the marker-less 429
     * instead of the 400/401 it earned. Inconclusive outcomes (500) never touch the budget.
     *
     * @Flow\SkipCsrfProtection
     */
    public function refreshAction(): string
    {
        $this->response->setContentType('application/json');
        $httpRequest = $this->request->getHttpRequest();

        try {
            $result = $this->agentRefreshTokenService->redeemRefreshToken($this->extractRefreshTokenFromBody());
        } catch (AgentRefreshTokenRejectedException $e) {
            $limited = $this->agentEndpointThrottle->isLimited($httpRequest, AgentEndpointThrottle::ENDPOINT_REFRESH);
            $this->agentEndpointThrottle->countRejection($httpRequest, AgentEndpointThrottle::ENDPOINT_REFRESH);

            if ($limited) {
                $this->response->setStatusCode(429);
                $this->response->setHttpHeader('Retry-After', (string)AgentEndpointThrottle::WINDOW_SECONDS);

                return json_encode([
                    'error' => 'Too Many Requests',
                    'message' => 'Too many rejected requests from this address; retry later.',
                ], JSON_THROW_ON_ERROR);
            }

            if ($e->getReason() === AgentRefreshTokenRejectedException::REASON_MALFORMED) {
                $this->response->setStatusCode(400);

                return json_encode([
                    'error' => 'Bad Request',
                    'message' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            }

            $this->response->setStatusCode(401);
            $this->response->setHttpHeader(JwtEntryPoint::ERROR_CODE_HEADER, self::ERROR_CODE);

            return json_encode([
                'error' => 'Unauthorized',
                'message' => $e->getMessage(),
                'errorCode' => self::ERROR_CODE,
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode($result, JSON_THROW_ON_ERROR);
    }

    /**
     * Extracts `refresh_token` from the JSON request body. Returns null for anything that
     * is not a JSON object carrying a string `refresh_token` - the service rejects null as
     * malformed, which is answered with a marker-less 400: no token was evaluated, so the
     * authoritative "this credential is dead" marker must not be attached to it.
     */
    private function extractRefreshTokenFromBody(): ?string
    {
        $body = (string)$this->request->getHttpRequest()->getBody();
        try {
            $decodedBody = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        if (!is_array($decodedBody) || !isset($decodedBody['refresh_token']) || !is_string($decodedBody['refresh_token'])) {
            return null;
        }

        return $decodedBody['refresh_token'];
    }
}

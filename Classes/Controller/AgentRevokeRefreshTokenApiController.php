<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\AgentEndpointThrottle;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;

/**
 * Server-to-server revoke endpoint for the opaque refresh credentials.
 *
 * POST neosidekick/api/agentic/revoke-refresh-token with JSON body {"refresh_token": "<opaque>"}.
 * The refresh token IS the credential here too: possession of a family's refresh secret is
 * the right to END that family, exactly as it is the right to renew it. The endpoint is
 * public via Policy.yaml, like the refresh endpoint next to it.
 *
 * Response contract (the Laravel revoke client is built against it exactly):
 *
 *  - 200 {}: the request carried a well-formed refresh token. Known, unknown,
 *    already-revoked and expired tokens are ALL answered this way - deliberately. The
 *    refresh endpoint already tells anyone willing to spend a request whether a token is
 *    live; this endpoint must not become a second, cheaper oracle for the same question,
 *    and its caller has nothing to do differently in either case.
 *  - 400: the request carried no syntactically well-formed refresh token at all (no body,
 *    no JSON, no `refresh_token` string, wrong shape) - the same malformed bound the
 *    refresh endpoint enforces, so nothing was ever looked up.
 *  - 429 + Retry-After: the client address is over budget on THIS endpoint - the budget is
 *    per address and per endpoint ({@see AgentEndpointThrottle}), so a burst of refresh
 *    rejections can never make a revoke land as 429. EVERY request to this endpoint spends
 *    one unit of that budget, well-formed or not, successful or not - the charge is uniform
 *    precisely so the 429 boundary carries no liveness signal either.
 *
 * Never GET: Flow's SecurityEntryPointMiddleware stores only safe requests as the
 * intercepted request for after-login redirects.
 */
class AgentRevokeRefreshTokenApiController extends ActionController
{
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
     * Revokes the whole rotation family named by the presented refresh token.
     *
     * The body is read straight off the HTTP request instead of through action arguments,
     * so a missing or malformed body deterministically takes the 400 path instead of
     * whatever Flow's property mapping would raise for it.
     *
     * Every request is booked against the budget on entry, before anything is looked up:
     * this endpoint has no success worth protecting (it answers 200 for live and dead
     * tokens alike), and a uniform charge keeps the 429 boundary free of any liveness signal.
     *
     * @Flow\SkipCsrfProtection
     */
    public function revokeAction(): string
    {
        $this->response->setContentType('application/json');
        $httpRequest = $this->request->getHttpRequest();

        if ($this->agentEndpointThrottle->isLimited($httpRequest, AgentEndpointThrottle::ENDPOINT_REVOKE)) {
            $this->response->setStatusCode(429);
            $this->response->setHttpHeader('Retry-After', (string)AgentEndpointThrottle::WINDOW_SECONDS);

            return json_encode([
                'error' => 'Too Many Requests',
                'message' => 'Too many rejected requests from this address; retry later.',
            ], JSON_THROW_ON_ERROR);
        }

        $this->agentEndpointThrottle->countRejection($httpRequest, AgentEndpointThrottle::ENDPOINT_REVOKE);

        $refreshToken = $this->extractRefreshTokenFromBody();
        if ($refreshToken === null) {
            $this->response->setStatusCode(400);

            return json_encode([
                'error' => 'Bad Request',
                'message' => 'The refresh token is missing or malformed.',
            ], JSON_THROW_ON_ERROR);
        }

        $this->agentRefreshTokenService->revokeFamilyOfRefreshToken($refreshToken);

        return json_encode(new \stdClass(), JSON_THROW_ON_ERROR);
    }

    /**
     * Extracts a well-formed `refresh_token` from the JSON request body, or null for
     * anything that is not a JSON object carrying one - which the action answers with the
     * marker-less 400 above.
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

        if (preg_match(AgentRefreshTokenService::REFRESH_TOKEN_PATTERN, $decodedBody['refresh_token']) !== 1) {
            return null;
        }

        return $decodedBody['refresh_token'];
    }
}

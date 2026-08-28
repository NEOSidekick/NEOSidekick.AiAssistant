<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Authentication\EntryPoint;

use GuzzleHttp\Psr7\Utils;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Authentication\EntryPoint\AbstractEntryPoint;
use Neos\Flow\Security\Context;
use NEOSidekick\AiAssistant\Security\Authentication\Token\JwtToken;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An authentication entry point that returns a 401 JSON response when JWT authentication fails.
 */
class JwtEntryPoint extends AbstractEntryPoint
{
    /**
     * Machine-readable marker telling the calling side that *this* 401 means "the JWT was
     * rejected", as opposed to any other 401 that may occur on the way (e.g. an intercepting
     * proxy). Emitted both in the body and as a header so the caller can discriminate without
     * parsing the body. Cross-repo contract: the Laravel side matches on this exact value.
     */
    public const ERROR_CODE = 'NEOS_JWT_REJECTED';

    /**
     * Response header carrying {@see ERROR_CODE}.
     */
    public const ERROR_CODE_HEADER = 'X-NEOSidekick-Error-Code';

    /**
     * Additional marker naming an expired token explicitly: emitted as the `errorReason`
     * body field and the {@see ERROR_REASON_HEADER} header, *alongside* the unchanged
     * ERROR_CODE marker, when the rejected token positively failed because its `exp`
     * passed (new-generation tokens only - legacy tokens carry no `exp`). Same exact/
     * case-sensitive matching convention as ERROR_CODE.
     */
    public const ERROR_REASON_EXPIRED = 'NEOS_JWT_EXPIRED';

    /**
     * Response header carrying {@see ERROR_REASON_EXPIRED}.
     */
    public const ERROR_REASON_HEADER = 'X-NEOSidekick-Error-Reason';

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * Starts the authentication: return 401 JSON response
     *
     * @param ServerRequestInterface $request The current request
     * @param ResponseInterface $response The current response
     * @return ResponseInterface
     */
    public function startAuthentication(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // The legacy `error`/`message` fields stay byte-identical: older callers still match on
        // them, and they are only retired together with the plugin support policy date.
        $bodyFields = [
            'error' => 'Unauthorized',
            'message' => 'Valid JWT Bearer token required',
            'errorCode' => self::ERROR_CODE,
        ];

        $tokenIsExpired = $this->rejectedTokenIsExpired();
        if ($tokenIsExpired) {
            $bodyFields['errorReason'] = self::ERROR_REASON_EXPIRED;
        }

        $body = json_encode($bodyFields, JSON_THROW_ON_ERROR);

        $response = $response->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(self::ERROR_CODE_HEADER, self::ERROR_CODE);

        if ($tokenIsExpired) {
            $response = $response->withHeader(self::ERROR_REASON_HEADER, self::ERROR_REASON_EXPIRED);
        }

        return $response->withBody(Utils::streamFor($body));
    }

    /**
     * Whether the JwtProvider recorded an expired-token rejection on a JwtToken of the
     * current request. Read-only: the security context is only consulted when it is
     * already initialized (it always is when the entry point runs after a failed
     * authentication; the guard keeps this side-effect-free everywhere else).
     */
    private function rejectedTokenIsExpired(): bool
    {
        if (!$this->securityContext instanceof Context || !$this->securityContext->isInitialized()) {
            return false;
        }

        foreach ($this->securityContext->getAuthenticationTokensOfType(JwtToken::class) as $token) {
            if ($token->getRejectionReason() === JwtToken::REJECTION_REASON_EXPIRED) {
                return true;
            }
        }

        return false;
    }
}

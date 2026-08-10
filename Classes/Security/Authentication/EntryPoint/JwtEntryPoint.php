<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Authentication\EntryPoint;

use GuzzleHttp\Psr7\Utils;
use Neos\Flow\Security\Authentication\EntryPoint\AbstractEntryPoint;
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
        $body = json_encode([
            'error' => 'Unauthorized',
            'message' => 'Valid JWT Bearer token required',
            'errorCode' => self::ERROR_CODE,
        ], JSON_THROW_ON_ERROR);

        return $response->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader(self::ERROR_CODE_HEADER, self::ERROR_CODE)
            ->withBody(Utils::streamFor($body));
    }
}

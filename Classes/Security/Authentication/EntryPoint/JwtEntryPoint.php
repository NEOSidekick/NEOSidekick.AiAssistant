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
     * Starts the authentication: return 401 JSON response
     *
     * @param ServerRequestInterface $request The current request
     * @param ResponseInterface $response The current response
     * @return ResponseInterface
     */
    public function startAuthentication(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_encode([
            'error' => 'Unauthorized',
            'message' => 'Valid JWT Bearer token required'
        ], JSON_THROW_ON_ERROR);

        return $response->withStatus(401)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Utils::streamFor($body));
    }
}

<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Security\Authentication\EntryPoint;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use NEOSidekick\AiAssistant\Security\Authentication\EntryPoint\JwtEntryPoint;
use PHPUnit\Framework\TestCase;

/**
 * The 401 emitted here is a cross-repo contract: the Laravel side discriminates "the Neos JWT was
 * rejected" from any other 401 on the way by the marker below, while older callers still match on
 * the legacy body fields. Both shapes are therefore pinned by these tests.
 */
class JwtEntryPointTest extends TestCase
{
    /**
     * @test
     */
    public function startAuthenticationReturnsUnauthorizedJsonResponse(): void
    {
        $response = $this->startAuthentication();

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    /**
     * @test
     */
    public function startAuthenticationKeepsLegacyBodyFields(): void
    {
        $body = $this->decodedBody();

        self::assertSame('Unauthorized', $body['error']);
        self::assertSame('Valid JWT Bearer token required', $body['message']);
    }

    /**
     * @test
     */
    public function startAuthenticationEmitsStructuredErrorCodeInBody(): void
    {
        $body = $this->decodedBody();

        self::assertSame('NEOS_JWT_REJECTED', $body['errorCode']);
    }

    /**
     * @test
     */
    public function startAuthenticationEmitsStructuredErrorCodeAsHeader(): void
    {
        $response = $this->startAuthentication();

        self::assertSame('NEOS_JWT_REJECTED', $response->getHeaderLine('X-NEOSidekick-Error-Code'));
    }

    private function startAuthentication(): \Psr\Http\Message\ResponseInterface
    {
        return (new JwtEntryPoint())->startAuthentication(
            new ServerRequest('GET', 'https://example.com/neosidekick/api/whoami'),
            new Response()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedBody(): array
    {
        return json_decode((string) $this->startAuthentication()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}

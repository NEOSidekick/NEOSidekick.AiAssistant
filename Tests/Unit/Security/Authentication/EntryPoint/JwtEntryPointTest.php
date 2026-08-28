<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Security\Authentication\EntryPoint;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Security\Context;
use NEOSidekick\AiAssistant\Security\Authentication\EntryPoint\JwtEntryPoint;
use NEOSidekick\AiAssistant\Security\Authentication\Token\JwtToken;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    /**
     * @test
     */
    public function startAuthenticationDoesNotEmitAnErrorReasonWithoutASecurityContext(): void
    {
        $response = $this->startAuthentication();

        self::assertArrayNotHasKey('errorReason', $this->decodedBody());
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Reason'));
    }

    /**
     * @test
     */
    public function startAuthenticationNamesAnExpiredTokenExplicitly(): void
    {
        $expiredToken = new JwtToken();
        $expiredToken->setRejectionReason(JwtToken::REJECTION_REASON_EXPIRED);
        $response = $this->startAuthentication($this->createInitializedContextWithTokens([$expiredToken]));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('NEOS_JWT_EXPIRED', $body['errorReason']);
        self::assertSame('NEOS_JWT_EXPIRED', $response->getHeaderLine('X-NEOSidekick-Error-Reason'));
        // The pinned rejected-marker stays untouched alongside the reason.
        self::assertSame('NEOS_JWT_REJECTED', $body['errorCode']);
        self::assertSame('NEOS_JWT_REJECTED', $response->getHeaderLine('X-NEOSidekick-Error-Code'));
        self::assertSame('Unauthorized', $body['error']);
        self::assertSame('Valid JWT Bearer token required', $body['message']);
    }

    /**
     * @test
     */
    public function startAuthenticationDoesNotEmitAnErrorReasonForANonExpiredRejection(): void
    {
        $rejectedToken = new JwtToken();
        $response = $this->startAuthentication($this->createInitializedContextWithTokens([$rejectedToken]));
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('errorReason', $body);
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Reason'));
    }

    /**
     * @test
     */
    public function startAuthenticationNeverInitializesAnUninitializedSecurityContext(): void
    {
        $securityContext = $this->createMock(Context::class);
        $securityContext->method('isInitialized')->willReturn(false);
        $securityContext->expects(self::never())->method('getAuthenticationTokensOfType');

        $response = $this->startAuthentication($securityContext);
        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('errorReason', $body);
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Reason'));
    }

    /**
     * @param JwtToken[] $tokens
     */
    private function createInitializedContextWithTokens(array $tokens): Context
    {
        $securityContext = $this->createMock(Context::class);
        $securityContext->method('isInitialized')->willReturn(true);
        $securityContext->method('getAuthenticationTokensOfType')->with(JwtToken::class)->willReturn($tokens);

        return $securityContext;
    }

    private function startAuthentication(?Context $securityContext = null): \Psr\Http\Message\ResponseInterface
    {
        $entryPoint = new JwtEntryPoint();
        if ($securityContext !== null) {
            $property = new ReflectionProperty(JwtEntryPoint::class, 'securityContext');
            $property->setAccessible(true);
            $property->setValue($entryPoint, $securityContext);
        }

        return $entryPoint->startAuthentication(
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

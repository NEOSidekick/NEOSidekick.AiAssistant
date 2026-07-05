<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use Neos\Flow\Security\Cryptography\HashService;
use NEOSidekick\AiAssistant\Service\PreviewTokenService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class PreviewTokenServiceTest extends TestCase
{
    private const TEST_KEY = 'unit-test-encryption-key';

    private PreviewTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in for Flow's HashService: deterministic HMAC with a fixed key
        $hashService = $this->createMock(HashService::class);
        $hashService->method('generateHmac')->willReturnCallback(
            static fn (string $string): string => hash_hmac('sha1', $string, self::TEST_KEY)
        );
        $hashService->method('validateHmac')->willReturnCallback(
            static fn (string $string, string $hmac): bool => hash_equals(
                hash_hmac('sha1', $string, self::TEST_KEY),
                $hmac
            )
        );

        $this->service = new PreviewTokenService();
        $property = new ReflectionProperty(PreviewTokenService::class, 'hashService');
        $property->setAccessible(true);
        $property->setValue($this->service, $hashService);
    }

    /**
     * @return array{nodeId: string, workspace: string, dimensions: string, expires: int, token: string}
     */
    private function generateAndParseQueryParams(string $dimensionsJson = '{}', ?int $ttl = null): array
    {
        $result = $this->service->generatePreviewPath('node-123', 'user-admin', $dimensionsJson, $ttl);
        $query = parse_url($result['previewPath'], PHP_URL_QUERY);
        parse_str((string)$query, $params);

        return [
            'nodeId' => (string)$params['nodeId'],
            'workspace' => (string)$params['workspace'],
            'dimensions' => (string)$params['dimensions'],
            'expires' => (int)$params['expires'],
            'token' => (string)$params['token'],
        ];
    }

    /** @test */
    public function itGeneratesAPreviewPathWithAllExpectedQueryParameters(): void
    {
        $result = $this->service->generatePreviewPath('node-123', 'user-admin', '{"language":["de"]}');

        $this->assertStringStartsWith('/neosidekick/preview?', $result['previewPath']);

        $params = $this->generateAndParseQueryParams('{"language":["de"]}');
        $this->assertSame('node-123', $params['nodeId']);
        $this->assertSame('user-admin', $params['workspace']);
        $this->assertSame('{"language":["de"]}', $params['dimensions']);
        $this->assertGreaterThan(time(), $params['expires']);
        $this->assertNotSame('', $params['token']);
    }

    /** @test */
    public function itUsesTheDefaultTtlOfFifteenMinutes(): void
    {
        $result = $this->service->generatePreviewPath('node-123', 'user-admin');

        $this->assertEqualsWithDelta(time() + PreviewTokenService::TOKEN_TTL, $result['expires'], 2);
        $this->assertSame($result['expires'], $result['expiresAt']->getTimestamp());
    }

    /** @test */
    public function itValidatesARoundTrippedToken(): void
    {
        $params = $this->generateAndParseQueryParams('{"language":["de"]}');

        $this->assertTrue($this->service->isTokenValid(
            $params['nodeId'],
            $params['workspace'],
            $params['dimensions'],
            $params['expires'],
            $params['token']
        ));
    }

    /** @test */
    public function itRejectsATamperedToken(): void
    {
        $params = $this->generateAndParseQueryParams();

        $this->assertFalse($this->service->isTokenValid(
            $params['nodeId'],
            $params['workspace'],
            $params['dimensions'],
            $params['expires'],
            $params['token'] . 'tampered'
        ));
    }

    /** @test */
    public function itRejectsAnEmptyToken(): void
    {
        $params = $this->generateAndParseQueryParams();

        $this->assertFalse($this->service->isTokenValid(
            $params['nodeId'],
            $params['workspace'],
            $params['dimensions'],
            $params['expires'],
            ''
        ));
    }

    /** @test */
    public function itRejectsTamperedParameters(): void
    {
        $params = $this->generateAndParseQueryParams();

        $this->assertFalse(
            $this->service->isTokenValid('other-node', $params['workspace'], $params['dimensions'], $params['expires'], $params['token']),
            'Changing the nodeId must invalidate the token'
        );
        $this->assertFalse(
            $this->service->isTokenValid($params['nodeId'], 'live', $params['dimensions'], $params['expires'], $params['token']),
            'Changing the workspace must invalidate the token'
        );
        $this->assertFalse(
            $this->service->isTokenValid($params['nodeId'], $params['workspace'], '{"language":["en"]}', $params['expires'], $params['token']),
            'Changing the dimensions must invalidate the token'
        );
        $this->assertFalse(
            $this->service->isTokenValid($params['nodeId'], $params['workspace'], $params['dimensions'], $params['expires'] + 60, $params['token']),
            'Changing the expiry must invalidate the token'
        );
    }

    /** @test */
    public function itRejectsAnExpiredToken(): void
    {
        $params = $this->generateAndParseQueryParams('{}', -60);

        $this->assertFalse($this->service->isTokenValid(
            $params['nodeId'],
            $params['workspace'],
            $params['dimensions'],
            $params['expires'],
            $params['token']
        ));
    }
}

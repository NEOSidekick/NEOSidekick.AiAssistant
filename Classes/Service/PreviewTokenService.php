<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use DateTimeImmutable;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Cryptography\HashService;

/**
 * Service for generating and validating signed preview URLs.
 *
 * A preview URL allows an external, unauthenticated screenshot service
 * (e.g. Browserless) to render a page in a possibly non-live workspace for
 * a limited time. The URL is protected by an HMAC token over all relevant
 * query parameters, signed with Flow's encryption key via the HashService.
 *
 * This service is the single source of truth for token generation and
 * validation, used by both the JWT-protected GetPreviewApiController and
 * the public PreviewRenderController.
 *
 * @Flow\Scope("singleton")
 */
class PreviewTokenService
{
    /**
     * Token lifetime in seconds (15 minutes)
     */
    public const TOKEN_TTL = 900;

    /**
     * URI path of the public preview endpoint (see Routes.yaml)
     */
    public const PREVIEW_PATH = '/neosidekick/preview';

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    /**
     * Generate a signed relative preview path for the given node.
     *
     * @param string $nodeId node aggregate identifier of the document node
     * @param string $workspace workspace name the node should be rendered in
     * @param string $dimensionsJson JSON-encoded dimensions, e.g. {"language":["de"]}
     * @param int|null $ttl token lifetime in seconds, defaults to TOKEN_TTL
     * @return array{previewPath: string, expires: int, expiresAt: DateTimeImmutable}
     */
    public function generatePreviewPath(
        string $nodeId,
        string $workspace,
        string $dimensionsJson = '{}',
        ?int $ttl = null
    ): array {
        $expires = time() + ($ttl ?? self::TOKEN_TTL);
        $token = $this->hashService->generateHmac(
            $this->canonicalString($nodeId, $workspace, $dimensionsJson, $expires)
        );

        $previewPath = self::PREVIEW_PATH . '?' . http_build_query([
            'nodeId' => $nodeId,
            'workspace' => $workspace,
            'dimensions' => $dimensionsJson,
            'expires' => $expires,
            'token' => $token,
        ]);

        return [
            'previewPath' => $previewPath,
            'expires' => $expires,
            'expiresAt' => (new DateTimeImmutable())->setTimestamp($expires),
        ];
    }

    /**
     * Validate the HMAC token and expiry for the given parameters.
     *
     * Returns false if the token does not match the parameters (tampering)
     * or if the expiry timestamp has passed.
     *
     * @param string $nodeId node aggregate identifier as passed in the URL
     * @param string $workspace workspace name as passed in the URL
     * @param string $dimensionsJson JSON-encoded dimensions as passed in the URL
     * @param int $expires unix timestamp until which the token is valid
     * @param string $token the HMAC token to validate
     */
    public function isTokenValid(
        string $nodeId,
        string $workspace,
        string $dimensionsJson,
        int $expires,
        string $token
    ): bool {
        if ($token === '') {
            return false;
        }

        $isHmacValid = $this->hashService->validateHmac(
            $this->canonicalString($nodeId, $workspace, $dimensionsJson, $expires),
            $token
        );

        if (!$isHmacValid) {
            return false;
        }

        return $expires >= time();
    }

    /**
     * Build the canonical string the HMAC is calculated over.
     */
    private function canonicalString(string $nodeId, string $workspace, string $dimensionsJson, int $expires): string
    {
        return implode('|', [$nodeId, $workspace, $dimensionsJson, (string)$expires]);
    }
}

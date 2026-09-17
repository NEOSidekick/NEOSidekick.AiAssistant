<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Security;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\RequestPattern\CsrfProtection;
use Neos\Flow\Tests\FunctionalTestCase;

/**
 * Regression guard for the silent re-authorization call path.
 *
 * The silent flow no longer posts `__csrfToken` in the body; it goes through the Neos UI's
 * `fetchWithErrorHandling`, which carries the token in the `X-Flow-Csrftoken` header. This test
 * exercises Flow's real CSRF request pattern against the real routed request for
 * `do-authorize.json`, so it fails if any of the three things the protection depends on regress:
 *
 *  - the action stops being covered by a method privilege (Policy.yaml) — CSRF would silently
 *    no longer apply at all,
 *  - `@Flow\SkipCsrfProtection` is (re-)added to authorizeAction,
 *  - the header form of the token stops being accepted.
 *
 * A pattern match of `true` means "this request is invalid" and Flow's CsrfTokenMissing
 * interceptor turns it into a 403 AccessDeniedException.
 */
class AgentAuthorizationCsrfProtectionTest extends FunctionalTestCase
{
    protected $testableSecurityEnabled = true;

    protected CsrfProtection $csrfProtection;

    public function setUp(): void
    {
        parent::setUp();
        $this->csrfProtection = $this->objectManager->get(CsrfProtection::class);
        $this->authenticateRoles(['Neos.Neos:Editor']);
    }

    /**
     * @test
     */
    public function doAuthorizeIsRejectedWithoutACsrfToken(): void
    {
        self::assertTrue($this->csrfProtection->matchRequest($this->routeDoAuthorizeRequest()));
    }

    /**
     * @test
     */
    public function doAuthorizeIsRejectedWithAnInvalidCsrfToken(): void
    {
        // Seed a token so the security context holds one at all: without any token Flow raises an
        // AuthenticationRequiredException ("possible session timeout") instead of reporting a
        // mismatch, which is a different branch than the one under test here.
        $this->securityContext->getCsrfProtectionToken();

        $request = $this->routeDoAuthorizeRequest(['X-Flow-Csrftoken' => 'not-a-valid-token']);

        self::assertTrue($this->csrfProtection->matchRequest($request));
    }

    /**
     * @test
     */
    public function doAuthorizeIsAcceptedWithAValidCsrfTokenInTheHeader(): void
    {
        $request = $this->routeDoAuthorizeRequest([
            'X-Flow-Csrftoken' => $this->securityContext->getCsrfProtectionToken(),
        ]);

        self::assertFalse($this->csrfProtection->matchRequest($request));
    }

    /**
     * @param array<string, string> $headers
     */
    private function routeDoAuthorizeRequest(array $headers = []): ActionRequest
    {
        $httpRequest = new ServerRequest(
            'POST',
            'http://localhost/neosidekick/agent/do-authorize.json',
            array_merge(['Content-Type' => 'application/x-www-form-urlencoded'], $headers),
            http_build_query(['state' => 'laravel-state'])
        );

        return $this->route($httpRequest);
    }
}

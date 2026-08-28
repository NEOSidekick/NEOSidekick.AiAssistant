<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Controller\AgentRefreshTokenApiController;
use NEOSidekick\AiAssistant\Service\AgentEndpointThrottle;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the HTTP contract of POST neosidekick/api/agentic/refresh-token through the full
 * routing/security/dispatch stack - the Laravel renewal client is built against exactly
 * this: the endpoint answers without any authentication (the refresh token IS the
 * credential), a token that was actually evaluated and positively rejected yields 401
 * with the case-sensitive NEOS_REFRESH_TOKEN_REJECTED marker in BOTH the errorCode body
 * field and the X-NEOSidekick-Error-Code header, a request carrying no well-formed token
 * at all yields a marker-less 400, and a valid token yields the 200 JSON with
 * jwt / refresh_token / refresh_token_expires_at.
 */
class AgentRefreshTokenApiControllerTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    protected static $testablePersistenceEnabled = true;

    protected AgentRefreshTokenService $agentRefreshTokenService;

    protected AgentTokenService $agentTokenService;


    protected AccountRepository $accountRepository;


    public function setUp(): void
    {
        parent::setUp();
        $this->agentRefreshTokenService = $this->objectManager->get(AgentRefreshTokenService::class);
        $this->agentTokenService = $this->objectManager->get(AgentTokenService::class);
        $this->accountRepository = $this->objectManager->get(AccountRepository::class);
        $this->objectManager->get(CacheManager::class)->getCache('NEOSidekick_AiAssistant_RefreshThrottle')->flush();
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function aValidRefreshTokenIsAnsweredWithTheRenewalResponse(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent');

        $response = $this->postRefreshToken(['refresh_token' => $opaqueToken]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Code'));

        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['jwt', 'refresh_token', 'refresh_token_expires_at'], array_keys($result));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['refresh_token']);
        self::assertIsInt($result['refresh_token_expires_at']);
        self::assertGreaterThan(time(), $result['refresh_token_expires_at']);

        $claims = $this->agentTokenService->verifyToken($result['jwt']);
        self::assertSame($account->getAccountIdentifier(), $claims['sub']);
        self::assertSame($accountUuid, $claims['account_id']);
        self::assertArrayNotHasKey('session_id', $claims);
    }

    /**
     * @test
     */
    public function anUnknownRefreshTokenIsAnsweredWithTheExactRejectionMarker(): void
    {
        $response = $this->postRefreshToken(['refresh_token' => bin2hex(random_bytes(32))]);

        $this->assertRejectionMarker($response);
    }

    /**
     * @test
     */
    public function aReplayedRefreshTokenIsAnsweredWithTheRejectionMarker(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent');
        $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        $response = $this->postRefreshToken(['refresh_token' => $opaqueToken]);

        $this->assertRejectionMarker($response);
    }

    /**
     * A request that carries no syntactically well-formed refresh token at all never had a
     * credential evaluated, so it must NOT carry the authoritative rejection marker: the
     * Laravel side would take that as proof the stored token is dead and de-authorize the
     * row. It is answered with a marker-less 400, which is inconclusive by contract.
     *
     * @test
     */
    public function aRequestWithoutAWellFormedRefreshTokenIsAnsweredMarkerlessWith400(): void
    {
        $this->assertMarkerlessBadRequest($this->postRefreshToken([]));
        $this->assertMarkerlessBadRequest($this->postRefreshToken(['refresh_token' => 'not-a-refresh-token']));
        $this->assertMarkerlessBadRequest($this->postRefreshToken(['refresh_token' => 12345]));
        $this->assertMarkerlessBadRequest($this->postRefreshTokenRaw(''));
    }

    /**
     * The marker is reserved for POSITIVELY identified rejections. An internal failure
     * inside the refresh grant (DB deadlock, mapping error, signing trouble) must surface
     * as a marker-less 500: the Laravel side treats the marker as proof and de-authorizes
     * the row, while a marker-less answer is inconclusive and leaves the token untouched.
     *
     * @test
     */
    public function anInternalFailureIsAnsweredMarkerlessInsteadOfWithTheRejectionMarker(): void
    {
        $failingService = new class () extends AgentRefreshTokenService {
            public function redeemRefreshToken(?string $opaqueToken): array
            {
                throw new \RuntimeException('Deadlock found when trying to get lock', 1755300099);
            }
        };
        $this->objectManager->setInstance(AgentRefreshTokenService::class, $failingService);

        try {
            $response = $this->postRefreshToken(['refresh_token' => bin2hex(random_bytes(32))]);
        } finally {
            $this->objectManager->setInstance(AgentRefreshTokenService::class, $this->agentRefreshTokenService);
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Code'), 'an internal error must not carry the rejection marker header');
        self::assertStringNotContainsString('NEOS_REFRESH_TOKEN_REJECTED', (string)$response->getBody());
    }

    /**
     * @test
     */
    public function aNonJsonBodyIsAnsweredMarkerlessWith400(): void
    {
        $this->assertMarkerlessBadRequest($this->postRefreshTokenRaw('this is not json'));
    }

    /**
     * A token revoked by an explicit backend logout was actually evaluated, so it keeps the
     * authoritative 401 + marker - the credential really is dead.
     *
     * @test
     */
    public function aRefreshTokenRevokedByLogoutIsAnsweredWithTheRejectionMarker(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent');
        $this->agentRefreshTokenService->revokeRefreshTokensOfAccounts([$accountUuid]);

        $this->assertRejectionMarker($this->postRefreshToken(['refresh_token' => $opaqueToken]));
    }

    /**
     * The rejection budget of the public endpoint, end to end: once an address has spent
     * the budgeted number of 4xx answers (seeded straight into the counter), its next
     * REJECTION is answered with 429 and a Retry-After naming the window - while a VALID
     * token from the very same address is still redeemed with 200, because the credential
     * is evaluated before the budget is consulted, and a DIFFERENT address is completely
     * unaffected. Together that is what
     * makes the throttle useless as a denial-of-service lever against the fleet's renewers.
     *
     * @test
     */
    public function anAddressThatSpendsItsRejectionBudgetIsThrottledWithoutAffectingOtherAddresses(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $validToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent-budget');

        $this->seedSpentRejectionBudget('198.51.100.13', AgentEndpointThrottle::ENDPOINT_REFRESH);

        $throttledResponse = $this->postRefreshToken(['refresh_token' => bin2hex(random_bytes(32))], '198.51.100.13');

        self::assertSame(429, $throttledResponse->getStatusCode());
        self::assertSame('60', $throttledResponse->getHeaderLine('Retry-After'));
        self::assertFalse($throttledResponse->hasHeader('X-NEOSidekick-Error-Code'), 'a throttled request never got a verdict about a credential, so it must stay marker-less');

        self::assertSame(
            200,
            $this->postRefreshToken(['refresh_token' => $validToken], '198.51.100.13')->getStatusCode(),
            'a valid token is redeemed no matter how much rejection budget its address burned'
        );

        self::assertSame(
            401,
            $this->postRefreshToken(['refresh_token' => bin2hex(random_bytes(32))], '203.0.113.13')->getStatusCode(),
            'the budget is per client address, so another address must still be served'
        );
    }

    /**
     * The 429 tests seed the counter, so nothing there proves the CONTROLLER ever charges
     * it. From an address whose budget is untouched, one rejected refresh must leave
     * exactly one unit spent - the wiring the seeded tests take for granted.
     *
     * @test
     */
    public function aRejectedRefreshChargesExactlyOneUnitOfTheAddressBudget(): void
    {
        $clientAddress = '198.51.100.14';
        $window = intdiv(time(), AgentEndpointThrottle::WINDOW_SECONDS);

        self::assertSame(
            401,
            $this->postRefreshToken(['refresh_token' => bin2hex(random_bytes(32))], $clientAddress)->getStatusCode()
        );

        self::assertSame(
            1,
            $this->countedRejections($clientAddress, AgentEndpointThrottle::ENDPOINT_REFRESH, $window),
            'the refresh controller must book exactly one rejection against the presenting address'
        );
    }

    /**
     * Reads back what the controller actually charged one address on one endpoint, summing
     * the current and the next window so a minute rolling between the request and this read
     * cannot lose the charge. Identifier format mirrors
     * {@see AgentEndpointThrottle::entryIdentifier()}, like {@see seedSpentRejectionBudget()}.
     */
    protected function countedRejections(string $clientAddress, string $endpoint, int $window): int
    {
        $cache = $this->objectManager->get(CacheManager::class)->getCache('NEOSidekick_AiAssistant_RefreshThrottle');

        $counted = 0;
        foreach ([$window, $window + 1] as $countedWindow) {
            $rejections = $cache->get('rejections_' . $endpoint . '_' . $countedWindow . '_' . sha1($clientAddress));
            $counted += is_int($rejections) ? $rejections : 0;
        }

        return $counted;
    }

    /**
     * Seeds the rejection counter of one address and endpoint at the full budget instead of
     * firing the budgeted number of real requests: the identifier is
     * `rejections_<endpoint>_<window number>_sha1(<client address>)`, mirroring
     * {@see AgentEndpointThrottle::entryIdentifier()}, and both the current and the next
     * window are seeded so a minute rolling between the seed and the request under test
     * cannot turn the assertion into a flake.
     */
    protected function seedSpentRejectionBudget(string $clientAddress, string $endpoint): void
    {
        $cache = $this->objectManager->get(CacheManager::class)->getCache('NEOSidekick_AiAssistant_RefreshThrottle');
        $window = intdiv(time(), AgentEndpointThrottle::WINDOW_SECONDS);

        foreach ([$window, $window + 1] as $seededWindow) {
            $cache->set(
                'rejections_' . $endpoint . '_' . $seededWindow . '_' . sha1($clientAddress),
                AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW,
                [],
                AgentEndpointThrottle::WINDOW_SECONDS
            );
        }
    }

    /**
     * Successful renewals are legitimate at any rate - the whole fleet's renewers produce
     * them - so no number of them may ever spend the budget.
     *
     * @test
     */
    public function successfulRefreshesNeverSpendTheRejectionBudget(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent-throttle');

        for ($renewal = 0; $renewal <= AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW; $renewal++) {
            $response = $this->postRefreshToken(['refresh_token' => $opaqueToken], '198.51.100.14');
            self::assertSame(200, $response->getStatusCode(), 'renewal ' . $renewal . ' must not be throttled');
            $opaqueToken = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR)['refresh_token'];
        }
    }

    /**
     * The marker is a cross-repo contract: both carriers, exact and case-sensitive, and
     * the value must stay distinct from the JWT marker.
     */
    protected function assertRejectionMarker(ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('NEOS_REFRESH_TOKEN_REJECTED', $response->getHeaderLine('X-NEOSidekick-Error-Code'));
        self::assertSame('NEOS_REFRESH_TOKEN_REJECTED', AgentRefreshTokenApiController::ERROR_CODE);

        $body = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('NEOS_REFRESH_TOKEN_REJECTED', $body['errorCode']);
        self::assertSame('Unauthorized', $body['error']);
    }

    /**
     * The counterpart of the marker assertion: a request that never produced a verdict
     * about a credential must not carry the marker in EITHER carrier.
     */
    protected function assertMarkerlessBadRequest(ResponseInterface $response): void
    {
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-NEOSidekick-Error-Code'));
        self::assertStringNotContainsString('NEOS_REFRESH_TOKEN_REJECTED', (string)$response->getBody());
    }

    /**
     * @param array<string, mixed> $bodyFields
     */
    protected function postRefreshToken(array $bodyFields, ?string $clientAddress = null): ResponseInterface
    {
        return $this->postRefreshTokenRaw(json_encode($bodyFields, JSON_THROW_ON_ERROR), $clientAddress);
    }

    /**
     * @param string|null $clientAddress Becomes REMOTE_ADDR, from which Flow's
     *                                   TrustedProxiesMiddleware derives the CLIENT_IP
     *                                   attribute the rejection budget is scoped to
     */
    protected function postRefreshTokenRaw(string $rawBody, ?string $clientAddress = null): ResponseInterface
    {
        $request = new ServerRequest(
            'POST',
            'http://localhost/neosidekick/api/agentic/refresh-token',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            null,
            '1.1',
            $clientAddress === null ? [] : ['REMOTE_ADDR' => $clientAddress]
        );

        return $this->browser->sendRequest($request->withBody(Utils::streamFor($rawBody)));
    }

    protected function createBackendAccountWithUser(): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('refresh-http-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $this->accountRepository->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Refresh', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }
}

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
use NEOSidekick\AiAssistant\Domain\Repository\AgentRefreshTokenRecordRepository;
use NEOSidekick\AiAssistant\Service\AgentEndpointThrottle;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the HTTP contract of POST neosidekick/api/agentic/revoke-refresh-token through the
 * full routing/security/dispatch stack - the Laravel revoke client is built against
 * exactly this: no authentication and no session (the refresh token IS the credential),
 * a flat 200 {} for known, unknown and already-revoked tokens alike so the endpoint never
 * becomes a liveness oracle, a 400 only for a body that carries no well-formed token, and
 * an actual family-wide revocation behind the 200 that the refresh endpoint then confirms
 * with its rejection marker.
 */
class AgentRevokeRefreshTokenApiControllerTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    protected static $testablePersistenceEnabled = true;

    protected AgentRefreshTokenService $agentRefreshTokenService;

    protected AgentRefreshTokenRecordRepository $recordRepository;


    protected AccountRepository $accountRepository;


    public function setUp(): void
    {
        parent::setUp();
        $this->agentRefreshTokenService = $this->objectManager->get(AgentRefreshTokenService::class);
        $this->recordRepository = $this->objectManager->get(AgentRefreshTokenRecordRepository::class);
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
    public function aKnownRefreshTokenIsAnsweredWithAnEmptyObjectAndRevokesItsWholeFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-revoke-http');
        $familyId = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken))->getFamilyId();

        $response = $this->postRevoke(['refresh_token' => $opaqueToken]);

        $this->assertEmptyObjectResponse($response);
        self::assertFalse($this->recordRepository->familyHasUnrevokedRecord($familyId), 'the family must be dead after the call');
    }

    /**
     * The endpoint must not become a cheaper liveness oracle than the refresh endpoint
     * already is: a token nobody ever minted is answered exactly like a live one.
     *
     * @test
     */
    public function anUnknownRefreshTokenIsAnsweredIdenticallyToAKnownOne(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $knownToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-revoke-oracle');

        $knownResponse = $this->postRevoke(['refresh_token' => $knownToken]);
        $unknownResponse = $this->postRevoke(['refresh_token' => bin2hex(random_bytes(32))]);

        $this->assertEmptyObjectResponse($knownResponse);
        $this->assertEmptyObjectResponse($unknownResponse);
        self::assertSame((string)$knownResponse->getBody(), (string)$unknownResponse->getBody());
        self::assertSame($knownResponse->getStatusCode(), $unknownResponse->getStatusCode());
    }

    /**
     * @test
     */
    public function anAlreadyRevokedRefreshTokenIsAnsweredWithTheSameEmptyObject(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-revoke-twice');

        $this->assertEmptyObjectResponse($this->postRevoke(['refresh_token' => $opaqueToken]));
        $this->assertEmptyObjectResponse($this->postRevoke(['refresh_token' => $opaqueToken]));
    }

    /**
     * A body that never carried a well-formed refresh token had nothing looked up, so it
     * gets the same 400 bound the refresh endpoint enforces - the only non-200 outcome
     * that is not about the credential's state.
     *
     * @test
     */
    public function aRequestWithoutAWellFormedRefreshTokenIsAnsweredWith400(): void
    {
        $this->assertBadRequest($this->postRevoke([]));
        $this->assertBadRequest($this->postRevoke(['refresh_token' => '']));
        $this->assertBadRequest($this->postRevoke(['refresh_token' => 12345]));
        $this->assertBadRequest($this->postRevoke(['refresh_token' => 'not-a-refresh-token']));
        $this->assertBadRequest($this->postRevoke(['refresh_token' => str_repeat('a', 65)]));
        $this->assertBadRequest($this->postRevoke(['refresh_token' => ['nested']]));
        $this->assertBadRequest($this->postRevokeRaw(''));
        $this->assertBadRequest($this->postRevokeRaw('this is not json'));
    }

    /**
     * GET must not route here at all: Flow's SecurityEntryPointMiddleware stores safe
     * requests as the intercepted request for after-login redirects, so a GET endpoint
     * can poison an editor's next backend login.
     *
     * @test
     */
    public function theEndpointIsNotReachableWithGet(): void
    {
        $response = $this->browser->sendRequest(
            new ServerRequest('GET', 'http://localhost/neosidekick/api/agentic/revoke-refresh-token')
        );

        self::assertContains($response->getStatusCode(), [404, 405]);
    }

    /**
     * The caller is a server on the other side of the internet: it holds no Neos session
     * and never gets one, and it is never redirected to a login.
     *
     * @test
     */
    public function theEndpointAnswersWithoutAnySessionOrAuthentication(): void
    {
        $response = $this->postRevoke(['refresh_token' => bin2hex(random_bytes(32))]);

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Location'), 'the endpoint must never redirect to a login');
        self::assertFalse($response->hasHeader('Set-Cookie'), 'the endpoint must never hand out a session cookie');
    }

    /**
     * The endpoint's whole point, end to end: after the revoke, the credential Laravel
     * just gave up is answered by the refresh endpoint with the authoritative rejection
     * marker - the family really is gone, not merely reported gone.
     *
     * @test
     */
    public function afterARevokeTheFamilysNextRefreshIsRejected(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-revoke-then-refresh');

        $this->assertEmptyObjectResponse($this->postRevoke(['refresh_token' => $opaqueToken]));

        $refreshResponse = $this->postRefresh(['refresh_token' => $opaqueToken]);

        self::assertSame(401, $refreshResponse->getStatusCode());
        self::assertSame('NEOS_REFRESH_TOKEN_REJECTED', $refreshResponse->getHeaderLine('X-NEOSidekick-Error-Code'));
        $body = json_decode((string)$refreshResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('The refresh token has been revoked.', $body['message']);
    }

    /**
     * The revoke endpoint has a rejection budget of its own, and spends it uniformly:
     * EVERY request costs one unit, well-formed or not, so the address that runs out is
     * answered with 429 and a Retry-After naming the window - and the 429 boundary itself
     * says nothing about whether any of the presented tokens was live.
     *
     * @test
     */
    public function theRejectionBudgetAppliesToTheRevokeEndpointToo(): void
    {
        $this->seedSpentRejectionBudget('198.51.100.31', AgentEndpointThrottle::ENDPOINT_REVOKE);

        $response = $this->postRevoke(['refresh_token' => bin2hex(random_bytes(32))], '198.51.100.31');

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->getHeaderLine('Retry-After'));
    }

    /**
     * The revoke endpoint spends its budget UNIFORMLY - a claim the seeded 429 test cannot
     * check, because a seeded counter proves nothing about what the controller charges.
     * A well-formed but unknown token is answered with 200, and must still cost exactly one
     * unit from an address whose budget is untouched.
     *
     * @test
     */
    public function anAnsweredRevokeChargesExactlyOneUnitOfTheAddressBudget(): void
    {
        $clientAddress = '198.51.100.33';
        $window = intdiv(time(), AgentEndpointThrottle::WINDOW_SECONDS);

        $this->assertEmptyObjectResponse(
            $this->postRevoke(['refresh_token' => bin2hex(random_bytes(32))], $clientAddress)
        );

        self::assertSame(
            1,
            $this->countedRejections($clientAddress, AgentEndpointThrottle::ENDPOINT_REVOKE, $window),
            'every revoke costs one unit, whether or not it ended a live family'
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

    protected function assertEmptyObjectResponse(ResponseInterface $response): void
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{}', (string)$response->getBody());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    protected function assertBadRequest(ResponseInterface $response): void
    {
        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('NEOS_REFRESH_TOKEN_REJECTED', (string)$response->getBody());
    }

    /**
     * @param array<string, mixed> $bodyFields
     */
    protected function postRevoke(array $bodyFields, ?string $clientAddress = null): ResponseInterface
    {
        return $this->postRevokeRaw(json_encode($bodyFields, JSON_THROW_ON_ERROR), $clientAddress);
    }

    protected function postRevokeRaw(string $rawBody, ?string $clientAddress = null): ResponseInterface
    {
        return $this->post('http://localhost/neosidekick/api/agentic/revoke-refresh-token', $rawBody, $clientAddress);
    }

    /**
     * @param array<string, mixed> $bodyFields
     */
    protected function postRefresh(array $bodyFields): ResponseInterface
    {
        return $this->post('http://localhost/neosidekick/api/agentic/refresh-token', json_encode($bodyFields, JSON_THROW_ON_ERROR));
    }

    protected function post(string $uri, string $rawBody, ?string $clientAddress = null): ResponseInterface
    {
        $request = new ServerRequest(
            'POST',
            $uri,
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
        $account->setAccountIdentifier('revoke-http-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $this->accountRepository->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Revoke', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }
}

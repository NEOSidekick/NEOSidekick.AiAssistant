<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Security;

use DateTime;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentRefreshTokenRecordRepository;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Security\Authentication\EntryPoint\JwtEntryPoint;
use NEOSidekick\AiAssistant\Security\Authentication\Provider\JwtProvider;
use NEOSidekick\AiAssistant\Security\Authentication\Token\JwtToken;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use RuntimeException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Provider-level functional tests for {@see JwtProvider}.
 *
 * The provider is the fleet-wide gate for every JWT-authenticated API call, so its
 * acceptance/rejection behavior is pinned here against the real AccountRepository and
 * the real session-metadata store:
 *
 *  - legacy (HS256, no `kid` header) tokens authenticate only while the embedded
 *    `session_id` exists in the live session store,
 *  - new-generation (RS256, `kid` header) tokens authenticate on signature + exp +
 *    active account, with no session-store dependency, but only while their `jti`
 *    resolves to a refresh token record whose FAMILY still holds an unrevoked row -
 *    the denylist that makes logout and password change kill unexpired access tokens,
 *  - a deactivated (expired) account is rejected regardless of token validity,
 *  - tampered signatures are rejected on both generations,
 *  - a new-generation token whose `account_id` claim does not match the account resolved
 *    via `sub` is rejected.
 */
class JwtProviderAuthenticationTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    protected static $testablePersistenceEnabled = true;

    protected JwtProvider $jwtProvider;

    protected AccountRepository $accountRepository;

    protected AgentTokenService $agentTokenService;

    protected AgentKeyPairService $agentKeyPairService;

    protected AgentRefreshTokenService $agentRefreshTokenService;

    protected AgentRefreshTokenRecordRepository $recordRepository;

    protected PartyService $partyService;

    protected PartyRepository $partyRepository;

    protected FrontendInterface $sessionMetaDataCache;

    public function setUp(): void
    {
        parent::setUp();
        $this->jwtProvider = JwtProvider::create('NEOSidekick.AiAssistant:JwtApi', []);
        $this->accountRepository = $this->objectManager->get(AccountRepository::class);
        $this->agentTokenService = $this->objectManager->get(AgentTokenService::class);
        $this->agentKeyPairService = $this->objectManager->get(AgentKeyPairService::class);
        $this->agentRefreshTokenService = $this->objectManager->get(AgentRefreshTokenService::class);
        $this->recordRepository = $this->objectManager->get(AgentRefreshTokenRecordRepository::class);
        $this->partyService = $this->objectManager->get(PartyService::class);
        $this->partyRepository = $this->objectManager->get(PartyRepository::class);
        $this->sessionMetaDataCache = $this->objectManager->get(CacheManager::class)->getCache('Flow_Session_MetaData');
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
    public function legacyTokenWithLiveSessionAndActiveAccountAuthenticates(): void
    {
        $account = $this->createBackendAccount();
        $sessionId = $this->registerLiveSession();
        $token = $this->createJwtToken($this->mintLegacyJwt($account->getAccountIdentifier(), $sessionId));

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertNotNull($token->getAccount());
        self::assertSame($account->getAccountIdentifier(), $token->getAccount()->getAccountIdentifier());
    }

    /**
     * @test
     */
    public function legacyTokenWithUnknownSessionIdIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $token = $this->createJwtToken(
            $this->mintLegacyJwt($account->getAccountIdentifier(), 'no-such-session-' . md5((string)mt_rand()))
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    /**
     * @test
     */
    public function legacyTokenForDeactivatedAccountIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $account->setExpirationDate(new DateTime('-1 day'));
        $this->accountRepository->update($account);
        $this->persistenceManager->persistAll();

        $sessionId = $this->registerLiveSession();
        $token = $this->createJwtToken($this->mintLegacyJwt($account->getAccountIdentifier(), $sessionId));

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    /**
     * @test
     */
    public function legacyTokenWithTamperedSignatureIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $sessionId = $this->registerLiveSession();
        $jwt = $this->mintLegacyJwt($account->getAccountIdentifier(), $sessionId);
        $tamperedJwt = $this->tamperWithPayload($jwt, ['sub' => 'somebody-else']);

        $token = $this->createJwtToken($tamperedJwt);

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    /**
     * @test
     */
    public function newGenerationTokenAuthenticatesWithoutAnySessionInTheStore(): void
    {
        $account = $this->createBackendAccount();
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);
        $token = $this->createJwtToken(
            $this->mintNewGenerationJwt($account, 'long-gone-session-' . md5((string)mt_rand()), ['jti' => $jti])
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertNotNull($token->getAccount());
        self::assertSame($account->getAccountIdentifier(), $token->getAccount()->getAccountIdentifier());
    }

    /**
     * @test
     */
    public function expiredNewGenerationTokenIsRejectedWithExpiredReason(): void
    {
        $account = $this->createBackendAccount();
        $token = $this->createJwtToken(
            $this->mintNewGenerationJwt($account, 'irrelevant-session', [
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ])
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertSame(JwtToken::REJECTION_REASON_EXPIRED, $token->getRejectionReason());
    }

    /**
     * @test
     */
    public function newGenerationTokenForDeactivatedAccountIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $account->setExpirationDate(new DateTime('-1 day'));
        $this->accountRepository->update($account);
        $this->persistenceManager->persistAll();

        $token = $this->createJwtToken(
            $this->mintNewGenerationJwt($account, 'irrelevant-session')
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    /**
     * @test
     */
    public function newGenerationTokenWithTamperedSignatureIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $jwt = $this->mintNewGenerationJwt($account, 'irrelevant-session');
        $token = $this->createJwtToken($this->tamperWithPayload($jwt, ['sub' => 'somebody-else']));

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertSame('', $token->getRejectionReason());
    }

    /**
     * @test
     */
    public function newGenerationTokenWithForeignAccountIdClaimIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $otherAccount = $this->createBackendAccount();
        $token = $this->createJwtToken(
            $this->mintNewGenerationJwt($account, 'irrelevant-session', [
                'account_id' => $this->persistenceManager->getIdentifierByObject($otherAccount),
            ])
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    /**
     * The liveness predicate is family-wide: a token minted alongside an unrevoked record
     * of a live family passes, exactly as before the check existed.
     *
     * @test
     */
    public function newGenerationTokenOfALiveFamilyAuthenticates(): void
    {
        $account = $this->createBackendAccount();
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);

        $token = $this->createJwtToken($this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $jti]));

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertSame($account->getAccountIdentifier(), $token->getAccount()->getAccountIdentifier());
    }

    /**
     * The regression this whole check exists for: a logout-style revocation of every
     * record of the family kills the unexpired access token INSTANTLY.
     *
     * The very same JWT is authenticated once BEFORE the revocation, so the test proves
     * the rejection is caused by the liveness read alone - signature, `exp`, `sub`,
     * `account_id` and the active-account check are all identical across the two calls,
     * and only the family's liveness changes between them.
     *
     * An UNRELATED live family of a DIFFERENT account exists throughout, which pins the
     * liveness query's scope to the record's own family: dropping the `familyId` conjunct
     * makes the foreign live row answer this token's liveness and turns the post-
     * revocation assertion green again, and scoping the query by `accountUuid` instead
     * breaks the pre-revocation precondition. Both mutations are caught here.
     *
     * @test
     */
    public function newGenerationTokenOfAFullyRevokedFamilyIsRejectedAndOnlyBecauseOfLiveness(): void
    {
        $account = $this->createBackendAccount();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);
        $jwt = $this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $jti]);

        $unrelatedAccount = $this->createBackendAccount();
        $unrelatedRecord = $this->createRefreshTokenRecord($unrelatedAccount, Algorithms::generateUUID());
        self::assertNotSame(
            $accountUuid,
            $unrelatedRecord->getAccountUuid(),
            'precondition: the unrelated live family belongs to a different account'
        );

        $tokenBeforeRevocation = $this->createJwtToken($jwt);
        $this->jwtProvider->authenticate($tokenBeforeRevocation);
        self::assertSame(
            TokenInterface::AUTHENTICATION_SUCCESSFUL,
            $tokenBeforeRevocation->getAuthenticationStatus(),
            'precondition: the token passes every check other than liveness'
        );

        $this->agentRefreshTokenService->revokeRefreshTokensOfAccounts([$accountUuid]);

        $tokenAfterRevocation = $this->createJwtToken($jwt);
        $this->jwtProvider->authenticate($tokenAfterRevocation);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $tokenAfterRevocation->getAuthenticationStatus());
        self::assertNull($tokenAfterRevocation->getAccount());
        self::assertFalse(
            $unrelatedRecord->isRevoked(),
            'the unrelated family stays live, so only the familyId scoping can explain the rejection'
        );
    }

    /**
     * The case a per-record-plus-grace-window predicate would provably leak: the
     * predecessor of a rotation is revoked with a fresh `rotatedAt`, so a grace window
     * would still honour it - but its family is dead, so family liveness rejects it the
     * moment logout lands.
     *
     * @test
     */
    public function aRotatedPredecessorTokenIsRejectedOnceTheWholeFamilyIsRevoked(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $predecessorJti = Algorithms::generateUUID();
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, $predecessorJti);
        $predecessorJwt = $this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $predecessorJti]);

        $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
        $this->agentRefreshTokenService->revokeRefreshTokensOfAccounts([$accountUuid]);

        $token = $this->createJwtToken($predecessorJwt);
        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
    }

    /**
     * No friction from rotation itself: the predecessor's own record is CAS-revoked, but
     * the successor keeps the family live, so an in-flight tool call carrying the
     * superseded JWT never 401s before its own `exp`.
     *
     * @test
     */
    public function aRotatedPredecessorTokenStillAuthenticatesWhileItsSuccessorLives(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $predecessorJti = Algorithms::generateUUID();
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, $predecessorJti);
        $predecessorJwt = $this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $predecessorJti]);

        $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        $predecessorRecord = $this->recordRepository->findOneByJti($predecessorJti);
        self::assertTrue($predecessorRecord->isRevoked(), 'precondition: rotation revokes the predecessor record itself');

        $token = $this->createJwtToken($predecessorJwt);
        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertSame($account->getAccountIdentifier(), $token->getAccount()->getAccountIdentifier());
    }

    /**
     * A new-generation token without a `jti` claim cannot be resolved to a record at all,
     * so it can never be revoked - it is rejected rather than trusted.
     *
     * @test
     */
    public function newGenerationTokenWithoutAJtiClaimIsRejected(): void
    {
        $account = $this->createBackendAccount();
        $this->createRefreshTokenRecord($account, Algorithms::generateUUID());

        $token = $this->createJwtToken($this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => null]));

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    /**
     * A correctly signed token whose `jti` matches no record - a record-less mint, or a
     * record wiped from the store - is rejected: every production mint writes its record.
     *
     * @test
     */
    public function newGenerationTokenWithAJtiThatMatchesNoRecordIsRejected(): void
    {
        $account = $this->createBackendAccount();

        $token = $this->createJwtToken(
            $this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => Algorithms::generateUUID()])
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    /**
     * Pins the fail-closed invariant: no path may reach authentication success without a
     * COMPLETED liveness read. A failing record store therefore propagates exactly like
     * the account lookup's failure would - the request fails and nothing authenticates.
     * A `catch` around the lookups added "for installs that have not migrated" would turn
     * this red.
     *
     * @test
     */
    public function newGenerationTokenIsNotAuthenticatedWhenTheRecordStoreFails(): void
    {
        $account = $this->createBackendAccount();
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);
        $token = $this->createJwtToken($this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $jti]));

        $failingRepository = $this->createMock(AgentRefreshTokenRecordRepository::class);
        $failingRepository->method('findOneByJti')
            ->willThrowException(new RuntimeException('Base table or view not found: agentrefreshtokenrecord'));

        $originalRepository = $this->replaceProviderRecordRepository($failingRepository);
        $caughtException = null;
        try {
            $this->jwtProvider->authenticate($token);
        } catch (\Throwable $e) {
            $caughtException = $e;
        } finally {
            $this->replaceProviderRecordRepository($originalRepository);
        }

        self::assertNotNull(
            $caughtException,
            'The repository failure must propagate instead of being swallowed into a success'
        );
        self::assertSame(RuntimeException::class, get_class($caughtException));
        self::assertStringContainsString('Base table or view not found', $caughtException->getMessage());

        self::assertNotSame(TokenInterface::AUTHENTICATION_SUCCESSFUL, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    /**
     * A keyless installation - the row was deleted, or a restore predates the key - answers a
     * new-generation token with the AUTHORITATIVE rejection: the token cannot be one this
     * installation signed, so the backend may treat the 401 and its marker as final. And the
     * verification path must stay read-only: it may never mint the key it is missing, because
     * a key nobody pushed would only create an inert identity on an unauthenticated path.
     *
     * @test
     */
    public function aNewGenerationTokenOnAKeylessInstallationIsRejectedWithTheAuthoritativeMarkerAndCreatesNoRow(): void
    {
        $account = $this->createBackendAccount();
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);
        $jwt = $this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $jti]);

        $this->clearSigningKeyRecord();
        $token = $this->createJwtToken($jwt);

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
        self::assertSame(0, $this->countSigningKeyRecords(), 'verification must never mint the missing key');

        $response = $this->objectManager->get(JwtEntryPoint::class)->startAuthentication(
            new ServerRequest('GET', 'http://localhost/neosidekick/api/whoami'),
            new Response()
        );
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            JwtEntryPoint::ERROR_CODE,
            $response->getHeaderLine(JwtEntryPoint::ERROR_CODE_HEADER),
            'a rejected token is what the marker is reserved for'
        );
    }

    /**
     * The counterpart of the test above, and the reason the provider loads the signing key
     * OUTSIDE its catch-all: a row that cannot be READ (a failover, a dropped connection) says
     * nothing about the token. Turning that into WRONG_CREDENTIALS would answer with the
     * authoritative marker and de-authorize a perfectly valid editor mid tool call, so the
     * storage failure must propagate as a marker-less error the backend retries instead.
     *
     * @test
     */
    public function aSigningKeyLoadFailureIsNotAnAuthoritativeRejection(): void
    {
        $account = $this->createBackendAccount();
        $jti = Algorithms::generateUUID();
        $this->createRefreshTokenRecord($account, $jti);
        $token = $this->createJwtToken($this->mintNewGenerationJwt($account, 'irrelevant-session', ['jti' => $jti]));

        $failingRepository = $this->createMock(AgentSigningKeyRecordRepository::class);
        $failingRepository->method('findInstallRecord')
            ->willThrowException(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $originalRepository = $this->replaceSigningKeyRecordRepository($failingRepository);
        $caughtException = null;
        try {
            $this->jwtProvider->authenticate($token);
        } catch (\Throwable $throwable) {
            $caughtException = $throwable;
        } finally {
            $this->replaceSigningKeyRecordRepository($originalRepository);
        }

        self::assertInstanceOf(
            AgentSigningKeyStorageException::class,
            $caughtException,
            'an unreadable signing-key row must propagate instead of becoming a verdict about the token'
        );
        self::assertNotSame(TokenInterface::WRONG_CREDENTIALS, $token->getAuthenticationStatus());
        self::assertNull($token->getAccount());
    }

    /**
     * @test
     */
    public function emptyBearerReportsNoCredentialsGiven(): void
    {
        $token = new JwtToken();
        $token->updateCredentials(
            ActionRequest::fromHttpRequest(new ServerRequest('GET', 'http://localhost/'))
        );

        $this->jwtProvider->authenticate($token);

        self::assertSame(TokenInterface::NO_CREDENTIALS_GIVEN, $token->getAuthenticationStatus());
    }

    protected function createBackendAccount(?string $identifier = null): Account
    {
        $account = new Account();
        $account->setAccountIdentifier($identifier ?? 'jwt-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $this->accountRepository->add($account);
        $this->persistenceManager->persistAll();

        return $account;
    }

    protected function createBackendAccountWithUser(): Account
    {
        $account = $this->createBackendAccount();

        $user = new User();
        $user->setName(new PersonName('', 'Jwt', '', 'Tester'));
        $this->partyRepository->add($user);
        $this->partyService->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }

    /**
     * Creates the refresh token record that pairs an access token's `jti` to a family -
     * the row the provider's liveness read resolves.
     */
    protected function createRefreshTokenRecord(Account $account, string $jti, ?string $familyId = null): AgentRefreshTokenRecord
    {
        $record = new AgentRefreshTokenRecord(
            hash('sha256', bin2hex(random_bytes(32))),
            $jti,
            $this->persistenceManager->getIdentifierByObject($account),
            $familyId ?? Algorithms::generateUUID(),
            new \DateTimeImmutable('+30 days'),
            new \DateTimeImmutable('+90 days'),
            AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT
        );
        $this->recordRepository->add($record);
        $this->persistenceManager->persistAll();

        return $record;
    }

    /**
     * Swaps the record repository the provider under test reads through, handing back the
     * previous value so the test can restore it.
     */
    protected function replaceProviderRecordRepository(object $recordRepository): object
    {
        $property = new ReflectionProperty(JwtProvider::class, 'agentRefreshTokenRecordRepository');
        $property->setAccessible(true);
        $previousValue = $property->getValue($this->jwtProvider);
        $property->setValue($this->jwtProvider, $recordRepository);

        return $previousValue;
    }

    /**
     * Registers a session in the real session-metadata store, exactly the shape
     * SessionManager::getSession() resurrects remote sessions from.
     */
    protected function registerLiveSession(): string
    {
        $sessionId = md5(uniqid('session-', true));
        $this->sessionMetaDataCache->set($sessionId, [
            'lastActivityTimestamp' => time(),
            'storageIdentifier' => Algorithms::generateUUID(),
            'tags' => [],
        ], ['session']);

        return $sessionId;
    }

    /**
     * Mints a pre-upgrade (HS256, no `kid` header) JWT with the exact legacy claim set,
     * signed with the same Flow encryption key the plugin signs with.
     */
    protected function mintLegacyJwt(string $accountIdentifier, string $sessionId, array $claimOverrides = []): string
    {
        $payload = array_merge([
            'iss' => 'NEOSidekick.AiAssistant',
            'iat' => time(),
            'sub' => $accountIdentifier,
            'user_id' => sha1('functional-test-user'),
            'account_id' => Algorithms::generateUUID(),
            'session_id' => $sessionId,
        ], $claimOverrides);

        return JWT::encode($payload, $this->getLegacyEncryptionKey(), 'HS256');
    }

    /**
     * Mints a new-generation JWT (RS256, keypair kid in the header, real exp) with the
     * pinned claim set, signed with the fixture private key.
     */
    protected function mintNewGenerationJwt(Account $account, string $sessionId, array $claimOverrides = []): string
    {
        $issuedAt = time();
        $payload = array_merge([
            'iss' => 'NEOSidekick.AiAssistant',
            'iat' => $issuedAt,
            'exp' => $issuedAt + AgentTokenService::ACCESS_TOKEN_LIFETIME,
            'jti' => Algorithms::generateUUID(),
            'sub' => $account->getAccountIdentifier(),
            'user_id' => sha1('functional-test-user'),
            'account_id' => $this->persistenceManager->getIdentifierByObject($account),
            'session_id' => $sessionId,
        ], $claimOverrides);
        $payload = array_filter($payload, static function ($claimValue) {
            return $claimValue !== null;
        });

        return JWT::encode(
            $payload,
            $this->agentKeyPairService->getPrivateKeyPem(),
            AgentTokenService::SIGNING_ALGORITHM,
            $this->agentKeyPairService->getKeyId()
        );
    }

    protected function getLegacyEncryptionKey(): string
    {
        $reflectionMethod = new ReflectionMethod(AgentTokenService::class, 'getEncryptionKeyFromHashService');
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invoke($this->agentTokenService);
    }

    protected function createJwtToken(string $jwt): JwtToken
    {
        $token = new JwtToken();
        $token->updateCredentials(
            ActionRequest::fromHttpRequest(
                new ServerRequest('GET', 'http://localhost/', ['Authorization' => 'Bearer ' . $jwt])
            )
        );

        return $token;
    }

    /**
     * Replaces payload claims without re-signing: the signature no longer matches.
     */
    protected function tamperWithPayload(string $jwt, array $claimOverrides): string
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true, 512, JSON_THROW_ON_ERROR);
        $tampered = JWT::urlsafeB64Encode(json_encode(array_merge($claims, $claimOverrides), JSON_THROW_ON_ERROR));

        return $header . '.' . $tampered . '.' . $signature;
    }
}

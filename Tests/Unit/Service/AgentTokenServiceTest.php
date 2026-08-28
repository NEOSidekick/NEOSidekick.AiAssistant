<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Session\SessionInterface;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Neos\Domain\Model\User;
use Neos\Neos\Service\UserService;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * Pins the two-generation token contract of AgentTokenService: the claim sets both the Laravel
 * side and pre-upgrade tokens bind on, and the strict kid discrimination in verifyToken().
 */
class AgentTokenServiceTest extends TestCase
{
    private const LEGACY_ENCRYPTION_KEY = 'unit-test-flow-encryption-key-0123456789abcdef0123456789abcdef';

    private const USER_UUID = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    private const ACCOUNT_UUID = '11111111-2222-3333-4444-555555555555';

    private const SESSION_ID = 'unit-test-session-id';

    private const ACCOUNT_IDENTIFIER = 'editor';

    private AgentTokenService $service;

    private AgentKeyPairService $keyPairService;

    private InMemoryAgentSigningKeyRecordRepository $repository;

    private Account $account;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new InMemoryAgentSigningKeyRecordRepository();
        $this->repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));
        $this->keyPairService = $this->createKeyPairService($this->repository);

        $user = $this->createMock(User::class);
        $account = new Account();
        $account->setAccountIdentifier(self::ACCOUNT_IDENTIFIER);
        $this->user = $user;
        $this->account = $account;

        $userService = $this->createMock(UserService::class);
        $userService->method('getBackendUser')->willReturn($user);

        $partyService = $this->createMock(PartyService::class);
        $partyService->method('getAssignedPartyOfAccount')->willReturnCallback(
            static function (Account $queriedAccount) use ($account, $user): ?User {
                return $queriedAccount === $account ? $user : null;
            }
        );

        $securityContext = $this->createMock(Context::class);
        $securityContext->method('getAccount')->willReturn($account);

        $session = $this->createMock(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->method('getId')->willReturn(self::SESSION_ID);
        $sessionManager = $this->createMock(SessionManagerInterface::class);
        $sessionManager->method('getCurrentSession')->willReturn($session);

        $persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManager->method('getIdentifierByObject')->willReturnCallback(
            static function (object $object) use ($user, $account): string {
                if ($object === $user) {
                    return self::USER_UUID;
                }
                if ($object === $account) {
                    return self::ACCOUNT_UUID;
                }

                return 'unexpected-object';
            }
        );

        $hashService = $this->createMock(HashService::class);
        $this->injectProperty($hashService, HashService::class, 'encryptionKey', self::LEGACY_ENCRYPTION_KEY);

        $this->service = new AgentTokenService();
        $this->injectProperty($this->service, AgentTokenService::class, 'userService', $userService);
        $this->injectProperty($this->service, AgentTokenService::class, 'securityContext', $securityContext);
        $this->injectProperty($this->service, AgentTokenService::class, 'sessionManager', $sessionManager);
        $this->injectProperty($this->service, AgentTokenService::class, 'hashService', $hashService);
        $this->injectProperty($this->service, AgentTokenService::class, 'persistenceManager', $persistenceManager);
        $this->injectProperty($this->service, AgentTokenService::class, 'agentKeyPairService', $this->keyPairService);
        $this->injectProperty($this->service, AgentTokenService::class, 'partyService', $partyService);
        $this->injectProperty($this->service, AgentTokenService::class, 'jwtSettings', [
            'algorithm' => 'HS256',
            'issuer' => 'NEOSidekick.AiAssistant',
        ]);
    }

    /** @test */
    public function generateTokenDataMintsAnRs256TokenWithTheKeypairKidInTheHeader(): void
    {
        $jwt = $this->service->generateTokenData()['jwt'];

        $header = $this->decodeHeader($jwt);
        self::assertSame('RS256', $header['alg']);
        self::assertSame($this->keyPairService->getKeyId(), $header['kid']);
    }

    /** @test */
    public function generateTokenDataMintsTheExactPinnedClaimSet(): void
    {
        $before = time();
        $tokenData = $this->service->generateTokenData();
        $after = time();

        $claims = (array)JWT::decode($tokenData['jwt'], new Key($this->keyPairService->getPublicKeyPem(), 'RS256'));

        self::assertSame('NEOSidekick.AiAssistant', $claims['iss']);
        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
        self::assertSame(sha1(self::USER_UUID), $claims['user_id']);
        self::assertSame(self::ACCOUNT_UUID, $claims['account_id']);
        self::assertSame(self::SESSION_ID, $claims['session_id']);
        self::assertGreaterThanOrEqual($before, $claims['iat']);
        self::assertLessThanOrEqual($after, $claims['iat']);
        self::assertSame($claims['iat'] + AgentTokenService::ACCESS_TOKEN_LIFETIME, $claims['exp']);
        self::assertSame($claims['iat'] + 3600, $claims['exp']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $claims['jti']
        );
        self::assertSame(
            ['iss', 'iat', 'exp', 'jti', 'sub', 'user_id', 'account_id', 'session_id'],
            array_keys($claims)
        );

        self::assertSame(sha1(self::USER_UUID), $tokenData['user_id']);
        self::assertSame(self::ACCOUNT_UUID, $tokenData['account_id']);
        self::assertSame(self::SESSION_ID, $tokenData['session_id']);
    }

    /** @test */
    public function generateTokenDataReturnsTheMintedJti(): void
    {
        $tokenData = $this->service->generateTokenData();
        $claims = $this->service->verifyToken($tokenData['jwt']);

        self::assertSame($claims['jti'], $tokenData['jti']);
    }

    /** @test */
    public function generateTokenDataForAccountMintsTheRenewalClaimSetWithoutASessionClaim(): void
    {
        $before = time();
        $tokenData = $this->service->generateTokenDataForAccount($this->account);
        $after = time();

        $claims = (array)JWT::decode($tokenData['jwt'], new Key($this->keyPairService->getPublicKeyPem(), 'RS256'));

        self::assertSame(
            ['iss', 'iat', 'exp', 'jti', 'sub', 'user_id', 'account_id'],
            array_keys($claims),
            'a renewal mint must not embed a session_id claim'
        );
        self::assertSame('NEOSidekick.AiAssistant', $claims['iss']);
        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
        self::assertSame(sha1(self::USER_UUID), $claims['user_id']);
        self::assertSame(self::ACCOUNT_UUID, $claims['account_id']);
        self::assertGreaterThanOrEqual($before, $claims['iat']);
        self::assertLessThanOrEqual($after, $claims['iat']);
        self::assertSame($claims['iat'] + AgentTokenService::ACCESS_TOKEN_LIFETIME, $claims['exp']);

        self::assertSame(sha1(self::USER_UUID), $tokenData['user_id']);
        self::assertSame(self::ACCOUNT_UUID, $tokenData['account_id']);
        self::assertSame($claims['jti'], $tokenData['jti']);
        self::assertArrayNotHasKey('session_id', $tokenData);

        $header = $this->decodeHeader($tokenData['jwt']);
        self::assertSame('RS256', $header['alg']);
        self::assertSame($this->keyPairService->getKeyId(), $header['kid']);
    }

    /** @test */
    public function generateTokenDataForAccountRejectsAnAccountWithoutAnAssignedUser(): void
    {
        $orphanedAccount = new Account();
        $orphanedAccount->setAccountIdentifier('orphan');

        try {
            $this->service->generateTokenDataForAccount($orphanedAccount);
            self::fail('Expected an AgentTokenException');
        } catch (AgentTokenException $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }

    /**
     * Mapping an infrastructure failure to 401 would make the refresh endpoint emit the
     * NEOS_REFRESH_TOKEN_REJECTED marker, which Laravel answers by de-authorizing the row over
     * what was a transient internal error.
     *
     * @test
     */
    public function generateTokenDataForAccountLetsInfrastructureFailuresEscapeInsteadOfMappingThemTo401(): void
    {
        $failingPartyService = $this->createMock(PartyService::class);
        $failingPartyService->method('getAssignedPartyOfAccount')
            ->willThrowException(new \RuntimeException('Deadlock found when trying to get lock'));
        $this->injectProperty($this->service, AgentTokenService::class, 'partyService', $failingPartyService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Deadlock');

        $this->service->generateTokenDataForAccount($this->account);
    }

    /**
     * The message is relayed verbatim into an authenticated editor's browser, where a DBAL error
     * or the state of the stored key is detail disclosure; the reference id is the only
     * correlator with the log.
     *
     * The service is built without a logger here, as in Flow's reflection-injected harness, so
     * this also pins that a null logger cannot turn a token failure into a TypeError.
     *
     * @test
     */
    public function aFailedMintIsReportedGenericallyWithAReferenceIdAndKeepsItsCause(): void
    {
        $cause = new \RuntimeException(
            'The stored agent signing private key is empty or not a parseable PEM private key.',
            1755300011
        );
        $failingKeyPairService = $this->createMock(AgentKeyPairService::class);
        $failingKeyPairService->method('getPrivateKeyPem')->willThrowException($cause);
        $this->injectProperty($this->service, AgentTokenService::class, 'agentKeyPairService', $failingKeyPairService);

        try {
            $this->service->generateTokenData();
            self::fail('Expected an AgentTokenException');
        } catch (AgentTokenException $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertSame('Internal Server Error', $e->getErrorType());
            self::assertMatchesRegularExpression('/^Failed to generate token\. Reference: [0-9a-f]{8}$/', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
        }
    }

    /** @test */
    public function aFailedEmbedTokenMintIsReportedGenericallyToo(): void
    {
        $failingKeyPairService = $this->createMock(AgentKeyPairService::class);
        $failingKeyPairService->method('getPrivateKeyPem')
            ->willThrowException(new \RuntimeException('Base table or view not found: agentsigningkey'));
        $this->injectProperty($this->service, AgentTokenService::class, 'agentKeyPairService', $failingKeyPairService);

        try {
            $this->service->generateEmbedToken();
            self::fail('Expected an AgentTokenException');
        } catch (AgentTokenException $e) {
            self::assertSame(500, $e->getStatusCode());
            self::assertMatchesRegularExpression('/^Failed to generate embed token\. Reference: [0-9a-f]{8}$/', $e->getMessage());
        }
    }

    /** @test */
    public function generatedJtiIsUniquePerToken(): void
    {
        $firstClaims = $this->service->verifyToken($this->service->generateTokenData()['jwt']);
        $secondClaims = $this->service->verifyToken($this->service->generateTokenData()['jwt']);

        self::assertNotSame($firstClaims['jti'], $secondClaims['jti']);
    }

    /** @test */
    public function generateLegacyTokenDataStaysByteCompatibleWithThePreUpgradeMint(): void
    {
        $jwt = $this->service->generateLegacyTokenData()['jwt'];

        $header = $this->decodeHeader($jwt);
        self::assertSame('HS256', $header['alg']);
        self::assertArrayNotHasKey('kid', $header);

        $claims = (array)JWT::decode($jwt, new Key(self::LEGACY_ENCRYPTION_KEY, 'HS256'));
        self::assertSame(
            ['iss', 'iat', 'sub', 'user_id', 'account_id', 'session_id'],
            array_keys($claims)
        );
        self::assertArrayNotHasKey('exp', $claims);
        self::assertArrayNotHasKey('jti', $claims);
        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
        self::assertSame(sha1(self::USER_UUID), $claims['user_id']);
        self::assertSame(self::ACCOUNT_UUID, $claims['account_id']);
        self::assertSame(self::SESSION_ID, $claims['session_id']);
    }

    /** @test */
    public function verifyTokenAcceptsALegacyHs256TokenWithoutKid(): void
    {
        $claims = $this->service->verifyToken($this->service->generateLegacyTokenData()['jwt']);

        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
        self::assertSame(self::SESSION_ID, $claims['session_id']);
    }

    /** @test */
    public function verifyTokenAcceptsANewGenerationRs256Token(): void
    {
        $claims = $this->service->verifyToken($this->service->generateTokenData()['jwt']);

        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
        self::assertArrayHasKey('exp', $claims);
    }

    /** @test */
    public function verifyTokenRejectsAnExpiredNewGenerationToken(): void
    {
        $jwt = $this->mintRs256Token(['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->expectException(ExpiredException::class);

        $this->service->verifyToken($jwt);
    }

    /** @test */
    public function verifyTokenAcceptsATokenExpiredWithinTheClockSkewLeeway(): void
    {
        $jwt = $this->mintRs256Token(['iat' => time() - 3600, 'exp' => time() - 30]);

        $claims = $this->service->verifyToken($jwt);

        self::assertSame(self::ACCOUNT_IDENTIFIER, $claims['sub']);
    }

    /** @test */
    public function verifyTokenRestoresTheGlobalLeewayAfterVerification(): void
    {
        $previousLeeway = JWT::$leeway;

        $this->service->verifyToken($this->service->generateTokenData()['jwt']);

        self::assertSame($previousLeeway, JWT::$leeway);
    }

    /** @test */
    public function verifyTokenRestoresTheGlobalLeewayWhenVerificationThrows(): void
    {
        $previousLeeway = JWT::$leeway;
        $jwt = $this->mintRs256Token(['iat' => time() - 7200, 'exp' => time() - 3600]);

        try {
            $this->service->verifyToken($jwt);
            self::fail('Expected an ExpiredException');
        } catch (ExpiredException $e) {
            self::assertSame($previousLeeway, JWT::$leeway);
        }
    }

    /** @test */
    public function verifyTokenNeverAcceptsHs256ForATokenCarryingOurKid(): void
    {
        $jwt = JWT::encode(
            $this->newGenerationClaims(),
            self::LEGACY_ENCRYPTION_KEY,
            'HS256',
            $this->keyPairService->getKeyId()
        );

        $this->expectException(UnexpectedValueException::class);

        $this->service->verifyToken($jwt);
    }

    /**
     * The canonical RS->HS confusion input: a verifier honouring the header `alg` would hand the
     * published public key PEM to the HMAC verifier as a secret, validating forged claims.
     *
     * @test
     */
    public function verifyTokenNeverAcceptsHs256ForgedWithOurOwnPublicKeyPem(): void
    {
        $jwt = JWT::encode(
            $this->newGenerationClaims(),
            $this->keyPairService->getPublicKeyPem(),
            'HS256',
            $this->keyPairService->getKeyId()
        );

        $this->expectException(UnexpectedValueException::class);

        $this->service->verifyToken($jwt);
    }

    /** @test */
    public function verifyTokenRejectsATokenWithAnUnknownKid(): void
    {
        $jwt = JWT::encode(
            $this->newGenerationClaims(),
            $this->fixturePrivateKeyPem(),
            'RS256',
            str_repeat('ab', 32)
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('unknown key id');

        $this->service->verifyToken($jwt);
    }

    /** @test */
    public function verifyTokenRejectsATamperedRs256Token(): void
    {
        $jwt = $this->service->generateTokenData()['jwt'];
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(JWT::urlsafeB64Decode($payload), true, 512, JSON_THROW_ON_ERROR);
        $claims['sub'] = 'somebody-else';
        $tampered = $header . '.' . JWT::urlsafeB64Encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.' . $signature;

        $this->expectException(SignatureInvalidException::class);

        $this->service->verifyToken($tampered);
    }

    /** @test */
    public function isNewGenerationTokenDiscriminatesOnTheKidHeader(): void
    {
        self::assertTrue($this->service->isNewGenerationToken($this->service->generateTokenData()['jwt']));
        self::assertFalse($this->service->isNewGenerationToken($this->service->generateLegacyTokenData()['jwt']));
        self::assertFalse($this->service->isNewGenerationToken('not-a-jwt'));
    }

    /**
     * @return array<string, mixed>
     */
    private function newGenerationClaims(array $overrides = []): array
    {
        $issuedAt = time();

        return array_merge([
            'iss' => 'NEOSidekick.AiAssistant',
            'iat' => $issuedAt,
            'exp' => $issuedAt + AgentTokenService::ACCESS_TOKEN_LIFETIME,
            'jti' => 'a4f1d2c3-0000-4000-8000-123456789abc',
            'sub' => self::ACCOUNT_IDENTIFIER,
            'user_id' => sha1(self::USER_UUID),
            'account_id' => self::ACCOUNT_UUID,
            'session_id' => self::SESSION_ID,
        ], $overrides);
    }

    private function mintRs256Token(array $claimOverrides = []): string
    {
        return JWT::encode(
            $this->newGenerationClaims($claimOverrides),
            $this->fixturePrivateKeyPem(),
            'RS256',
            $this->keyPairService->getKeyId()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeHeader(string $jwt): array
    {
        return json_decode(
            JWT::urlsafeB64Decode(explode('.', $jwt)[0]),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * JwtProvider calls this before its catch-all, so a signing-key row that cannot be READ must
     * escape as the storage exception. Swallowing it here would let the catch-all answer the
     * backend with the authoritative rejection marker and de-authorize the editor over a
     * transient database failure.
     *
     * @test
     */
    public function preloadSigningKeyPropagatesAStorageFailureForATokenCarryingAKid(): void
    {
        $jwt = $this->service->generateTokenData()['jwt'];
        $this->injectProperty(
            $this->service,
            AgentTokenService::class,
            'agentKeyPairService',
            $this->createKeyPairServiceFailingToLoad()
        );

        $this->expectException(AgentSigningKeyStorageException::class);

        $this->service->preloadSigningKey($jwt);
    }

    /**
     * A legacy token is verified with Flow's encryption key, so an unreadable signing-key row is
     * none of its business - preloading must not turn a working legacy session into an error.
     *
     * @test
     */
    public function preloadSigningKeyTouchesNoKeyForALegacyToken(): void
    {
        $jwt = $this->service->generateLegacyTokenData()['jwt'];
        $this->injectProperty(
            $this->service,
            AgentTokenService::class,
            'agentKeyPairService',
            $this->createKeyPairServiceFailingToLoad()
        );

        $this->service->preloadSigningKey($jwt);

        self::assertSame(self::ACCOUNT_IDENTIFIER, $this->service->verifyToken($jwt)['sub']);
    }

    /**
     * The counterpart of the test above: on an installation that simply has no key, the token
     * cannot be ours, so verification fails with the missing-keypair error - which JwtProvider's
     * catch-all is meant to turn into the authoritative rejection.
     *
     * @test
     */
    public function verifyTokenOnAKeylessInstallationFailsWithTheMissingKeypairErrorNotAStorageError(): void
    {
        $jwt = $this->service->generateTokenData()['jwt'];
        $keylessKeyPairService = $this->createKeyPairService(new InMemoryAgentSigningKeyRecordRepository());
        $this->injectProperty($this->service, AgentTokenService::class, 'agentKeyPairService', $keylessKeyPairService);

        $this->service->preloadSigningKey($jwt);

        try {
            $this->service->verifyToken($jwt);
            self::fail('Expected a RuntimeException for the missing keypair');
        } catch (\RuntimeException $e) {
            self::assertSame(1755300005, $e->getCode());
            self::assertNotInstanceOf(AgentSigningKeyStorageException::class, $e);
        }
    }

    private function createKeyPairService(InMemoryAgentSigningKeyRecordRepository $repository): AgentKeyPairService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);

        $service = new AgentKeyPairService();
        $this->injectProperty($service, AgentKeyPairService::class, 'agentSigningKeyRecordRepository', $repository);
        $this->injectProperty($service, AgentKeyPairService::class, 'entityManager', $entityManager);

        return $service;
    }

    private function createKeyPairServiceFailingToLoad(): AgentKeyPairService
    {
        $repository = new InMemoryAgentSigningKeyRecordRepository();
        $repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));
        $repository->failLoads(new \RuntimeException('MySQL server has gone away'));

        return $this->createKeyPairService($repository);
    }

    private function fixturePrivateKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pem');
    }

    private function fixturePublicKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem');
    }

    private function injectProperty(object $target, string $className, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Flow\Utility\Algorithms;
use Neos\Flow\Security\Account;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use Neos\Neos\Service\UserService;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use UnexpectedValueException;

/**
 * Generates JWT tokens for external agents.
 *
 * Two generations coexist: new-generation tokens are RS256-signed with the plugin keypair and
 * carry a `kid` and a real `exp`; legacy tokens are HS256-signed with Flow's encryption key and
 * live only as long as the Neos session. Legacy tokens minted before the upgrade must stay
 * verifiable until they are naturally replaced.
 *
 * @Flow\Scope("singleton")
 */
class AgentTokenService
{
    /**
     * Deliberately independent of the per-install session inactivityTimeout.
     */
    public const ACCESS_TOKEN_LIFETIME = 3600;

    /**
     * Clock-skew leeway in seconds. firebase/php-jwt only supports a static global, so it is set
     * for the duration of a decode call and restored afterwards.
     */
    public const VERIFICATION_LEEWAY_SECONDS = 60;

    public const SIGNING_ALGORITHM = 'RS256';

    /**
     * The Laravel verifier caps accepted embed token lifetimes at 300s, so this must stay below.
     */
    public const EMBED_TOKEN_LIFETIME = 120;

    /**
     * Cross-repo discriminator: access JWTs must never carry a `purpose` claim and embed tokens
     * must carry exactly this one, so neither can be replayed as the other.
     */
    public const EMBED_TOKEN_PURPOSE = 'embed';

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected UserService $userService;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected SessionManagerInterface $sessionManager;

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected HashService $hashService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * @Flow\Inject
     * @var AgentKeyPairService
     */
    protected AgentKeyPairService $agentKeyPairService;

    /**
     * @Flow\Inject
     * @var PartyService
     */
    protected PartyService $partyService;

    /**
     * @Flow\InjectConfiguration(path="jwt")
     * @var array{algorithm: string, issuer: string}
     */
    protected array $jwtSettings;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * New-generation token data for the current authenticated user.
     *
     * The claim contract is pinned, the NEOSidekick backend binds on it: user_id = sha1 of the
     * Neos User's persistence UUID, account_id = the Account's raw persistence UUID (not hashed),
     * sub = the account identifier. `session_id` is embedded for legacy consumers only and no
     * longer governs this generation's validity.
     *
     * @return array{user_id: string, account_id: string, session_id: string, jwt: string, jti: string}
     * @throws AgentTokenException When authentication fails or token generation fails
     */
    public function generateTokenData(): array
    {
        [$user, $account, $session] = $this->requireAuthenticatedUserAccountAndSession();

        try {
            $userId = sha1($this->persistenceManager->getIdentifierByObject($user));
            $accountId = $this->persistenceManager->getIdentifierByObject($account);
            $sessionId = $session->getId();
            $jti = Algorithms::generateUUID();

            $jwt = $this->mintNewGenerationJwt([
                'jti' => $jti,
                'sub' => $account->getAccountIdentifier(),
                'user_id' => $userId,
                'account_id' => $accountId,
                'session_id' => $sessionId,
            ]);

            return [
                'user_id' => $userId,
                'account_id' => $accountId,
                'session_id' => $sessionId,
                'jwt' => $jwt,
                'jti' => $jti,
            ];
        } catch (\Throwable $e) {
            $reference = $this->logTokenGenerationFailure($e, __METHOD__);

            throw new AgentTokenException(
                'Failed to generate token. Reference: ' . $reference,
                500,
                'Internal Server Error',
                $e
            );
        }
    }

    /**
     * The renewal mint: identity comes from the Account alone, which the refresh grant resolved
     * from its token record and never from caller input. Claims follow {@see generateTokenData()}
     * minus `session_id`.
     *
     * The party lookup is deliberately not wrapped: 401 is reserved for the positively identified
     * "no assigned Neos user" case, because the refresh endpoint turns it into the cross-repo
     * NEOS_REFRESH_TOKEN_REJECTED marker Laravel de-authorizes on, while an infrastructure failure
     * must surface as a marker-less 500.
     *
     * @return array{user_id: string, account_id: string, jwt: string, jti: string}
     * @throws AgentTokenException When the account has no assigned Neos user or signing fails
     */
    public function generateTokenDataForAccount(Account $account): array
    {
        $user = $this->partyService->getAssignedPartyOfAccount($account);
        if ($user === null) {
            throw new AgentTokenException(
                'The account has no assigned Neos user.',
                401,
                'Unauthorized'
            );
        }

        try {
            $userId = sha1($this->persistenceManager->getIdentifierByObject($user));
            $accountId = $this->persistenceManager->getIdentifierByObject($account);
            $jti = Algorithms::generateUUID();

            $jwt = $this->mintNewGenerationJwt([
                'jti' => $jti,
                'sub' => $account->getAccountIdentifier(),
                'user_id' => $userId,
                'account_id' => $accountId,
            ]);

            return [
                'user_id' => $userId,
                'account_id' => $accountId,
                'jwt' => $jwt,
                'jti' => $jti,
            ];
        } catch (\Throwable $e) {
            $reference = $this->logTokenGenerationFailure($e, __METHOD__);

            throw new AgentTokenException(
                'Failed to generate token. Reference: ' . $reference,
                500,
                'Internal Server Error',
                $e
            );
        }
    }

    /**
     * Mints the embed token gating the chat bootstrap's binding-token release: possessing it
     * proves a live Neos backend session as exactly this editor, at mint time.
     *
     * The claim set is pinned - Laravel's NeosSignedJwtVerifier::verifyEmbedToken() binds on it
     * exactly, and on nothing else being present. It omits iss/sub/account_id by design, so an
     * embed token can never resemble an access JWT.
     *
     * @throws AgentTokenException When no backend user is authenticated or signing fails
     */
    public function generateEmbedToken(): string
    {
        [$user] = $this->requireAuthenticatedUserAndAccount();

        try {
            $issuedAt = time();

            return JWT::encode(
                [
                    'purpose' => self::EMBED_TOKEN_PURPOSE,
                    'user_id' => sha1($this->persistenceManager->getIdentifierByObject($user)),
                    'jti' => Algorithms::generateUUID(),
                    'iat' => $issuedAt,
                    'exp' => $issuedAt + self::EMBED_TOKEN_LIFETIME,
                ],
                $this->agentKeyPairService->getPrivateKeyPem(),
                self::SIGNING_ALGORITHM,
                $this->agentKeyPairService->getKeyId()
            );
        } catch (\Throwable $e) {
            $reference = $this->logTokenGenerationFailure($e, __METHOD__);

            throw new AgentTokenException(
                'Failed to generate embed token. Reference: ' . $reference,
                500,
                'Internal Server Error',
                $e
            );
        }
    }

    /**
     * The caller's message is relayed verbatim into an authenticated editor's browser, where a
     * DBAL error or a filesystem path is detail disclosure - so the detail goes here and the
     * returned reference id is the only correlator between the two.
     *
     * Logged BEFORE the exception is constructed, so a failing logger can never swallow the throw.
     * `?->` because the injected logger is null in the reflection-built unit harness.
     */
    private function logTokenGenerationFailure(\Throwable $e, string $method): string
    {
        $reference = bin2hex(random_bytes(4));

        $this->logger?->error(
            sprintf(
                'NEOSidekick agent token generation failed in %s (reference %s): %s',
                $method,
                $reference,
                $e->getMessage()
            ),
            LogEnvironment::fromMethodName($method)
        );

        return $reference;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function mintNewGenerationJwt(array $claims): string
    {
        $issuedAt = time();

        return JWT::encode(
            array_merge([
                'iss' => $this->jwtSettings['issuer'] ?? 'NEOSidekick.AiAssistant',
                'iat' => $issuedAt,
                'exp' => $issuedAt + self::ACCESS_TOKEN_LIFETIME,
            ], $claims),
            $this->agentKeyPairService->getPrivateKeyPem(),
            self::SIGNING_ALGORITHM,
            $this->agentKeyPairService->getKeyId()
        );
    }

    /**
     * Kept byte-compatible with the pre-upgrade mint path, down to the absent kid/exp/jti.
     *
     * @return array{user_id: string, account_id: string, session_id: string, jwt: string}
     * @throws AgentTokenException When authentication fails or token generation fails
     */
    public function generateLegacyTokenData(): array
    {
        [$user, $account, $session] = $this->requireAuthenticatedUserAccountAndSession();

        try {
            $userId = sha1($this->persistenceManager->getIdentifierByObject($user));
            $accountId = $this->persistenceManager->getIdentifierByObject($account);
            $sessionId = $session->getId();

            $payload = [
                'iss' => $this->jwtSettings['issuer'] ?? 'NEOSidekick.AiAssistant',
                'iat' => time(),
                'sub' => $account->getAccountIdentifier(),
                'user_id' => $userId,
                'account_id' => $accountId,
                'session_id' => $sessionId,
            ];

            $encryptionKey = $this->getEncryptionKeyFromHashService();
            $algorithm = $this->jwtSettings['algorithm'] ?? 'HS256';
            $jwt = JWT::encode($payload, $encryptionKey, $algorithm);

            return [
                'user_id' => $userId,
                'account_id' => $accountId,
                'session_id' => $sessionId,
                'jwt' => $jwt,
            ];
        } catch (\Throwable $e) {
            $reference = $this->logTokenGenerationFailure($e, __METHOD__);

            throw new AgentTokenException(
                'Failed to generate token. Reference: ' . $reference,
                500,
                'Internal Server Error',
                $e
            );
        }
    }

    /**
     * Verify and decode a JWT, discriminated strictly on the `kid` header.
     *
     * A token carrying a kid is never verified with HS256 and an unknown kid is rejected outright:
     * honouring the header `alg` would let an attacker HMAC forged claims with the public key.
     *
     * @param string $jwt The raw JWT string
     * @return array The decoded payload (sub, session_id, user_id, account_id, etc.)
     * @throws \Firebase\JWT\ExpiredException When the token has expired
     * @throws \Firebase\JWT\SignatureInvalidException When the signature is invalid
     * @throws UnexpectedValueException When the token is malformed or carries an unknown kid
     * @throws \InvalidArgumentException When the token is malformed
     */
    public function verifyToken(string $jwt): array
    {
        $keyId = $this->extractKeyId($jwt);
        if ($keyId !== null) {
            if (!hash_equals($this->agentKeyPairService->getKeyId(), $keyId)) {
                throw new UnexpectedValueException('The token was signed with an unknown key id.', 1755300010);
            }

            return $this->decodeWithScopedLeeway(
                $jwt,
                new Key($this->agentKeyPairService->getPublicKeyPem(), self::SIGNING_ALGORITHM)
            );
        }

        $encryptionKey = $this->getEncryptionKeyFromHashService();
        $algorithm = $this->jwtSettings['algorithm'] ?? 'HS256';
        $decoded = JWT::decode($jwt, new Key($encryptionKey, $algorithm));
        return (array) $decoded;
    }

    /**
     * Loads the signing key a kid-carrying token will be verified against - and nothing else.
     *
     * JwtProvider calls this BEFORE its catch-all around {@see verifyToken()}: a storage
     * exception propagates so the provider answers marker-less, while a genuinely missing key is
     * left to {@see verifyToken()} to reject.
     *
     * @throws AgentSigningKeyStorageException When the signing-key row could not be loaded
     */
    public function preloadSigningKey(string $jwt): void
    {
        if ($this->extractKeyId($jwt) === null) {
            return;
        }

        $this->agentKeyPairService->hasKeyPair();
    }

    /**
     * Only meaningful alongside {@see verifyToken()} - this inspects the unverified header and
     * proves nothing on its own.
     */
    public function isNewGenerationToken(string $jwt): bool
    {
        $keyId = $this->extractKeyId($jwt);

        return $keyId !== null && hash_equals($this->agentKeyPairService->getKeyId(), $keyId);
    }

    /**
     * Null for an unparseable string too - the subsequent decode raises the canonical error.
     */
    private function extractKeyId(string $jwt): ?string
    {
        $segments = explode('.', $jwt);
        if (count($segments) !== 3) {
            return null;
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_object($header) || !property_exists($header, 'kid') || !is_string($header->kid)) {
            return null;
        }

        return $header->kid;
    }

    /**
     * @return array The decoded payload
     */
    private function decodeWithScopedLeeway(string $jwt, Key $key): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::VERIFICATION_LEEWAY_SECONDS;
        try {
            $decoded = JWT::decode($jwt, $key);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        return (array)$decoded;
    }

    /**
     * @return array{0: \Neos\Neos\Domain\Model\User, 1: \Neos\Flow\Security\Account}
     * @throws AgentTokenException When no backend user or account is present
     */
    private function requireAuthenticatedUserAndAccount(): array
    {
        $user = $this->userService->getBackendUser();
        $account = $this->securityContext->getAccount();

        if ($user === null || $account === null) {
            throw new AgentTokenException(
                'Authentication required. Please log in to the Neos backend.',
                401,
                'Unauthorized'
            );
        }

        return [$user, $account];
    }

    /**
     * @return array{0: \Neos\Neos\Domain\Model\User, 1: \Neos\Flow\Security\Account, 2: \Neos\Flow\Session\SessionInterface}
     * @throws AgentTokenException When no backend user, account or started session is present
     */
    private function requireAuthenticatedUserAccountAndSession(): array
    {
        [$user, $account] = $this->requireAuthenticatedUserAndAccount();

        $session = $this->sessionManager->getCurrentSession();
        if (!$session->isStarted()) {
            throw new AgentTokenException(
                'No active session. Please log in to the Neos backend.',
                401,
                'Unauthorized'
            );
        }

        return [$user, $account, $session];
    }

    /**
     * Reflection, so the legacy path needs no secret configuration of its own.
     */
    private function getEncryptionKeyFromHashService(): string
    {
        $this->hashService->generateHmac('init');
        $reflectionClass = new ReflectionClass($this->hashService);
        $encryptionKeyProperty = $reflectionClass->getProperty('encryptionKey');
        $encryptionKeyProperty->setAccessible(true);
        $encryptionKey = $encryptionKeyProperty->getValue($this->hashService);

        if ($encryptionKey === null) {
            $reflectionMethod = $reflectionClass->getMethod('getEncryptionKey');
            $reflectionMethod->setAccessible(true);
            $encryptionKey = $reflectionMethod->invoke($this->hashService);
        }

        return $encryptionKey;
    }
}

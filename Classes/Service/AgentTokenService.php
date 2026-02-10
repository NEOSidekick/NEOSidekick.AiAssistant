<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Firebase\JWT\JWT;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Session\SessionManagerInterface;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use Neos\Neos\Service\UserService;
use ReflectionClass;

/**
 * Service for generating JWT tokens for external agents.
 *
 * Returns user/account ID, session ID, and a signed JWT token that can be used
 * to authenticate against future write endpoints. Token validity is tied to the
 * Neos session - when the session expires, the token becomes invalid.
 *
 * @Flow\Scope("singleton")
 */
class AgentTokenService
{
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
     * @Flow\InjectConfiguration(path="jwt")
     * @var array{algorithm: string, issuer: string}
     */
    protected array $jwtSettings;

    /**
     * Generate token data for the current authenticated user.
     *
     * Returns user_id, account_id, session_id and jwt.
     * Token never expires but is invalid when the Neos session expires.
     *
     * @return array{user_id: string, account_id: string, session_id: string, jwt: string}
     * @throws AgentTokenException When authentication fails or token generation fails
     */
    public function generateTokenData(): array
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

        $session = $this->sessionManager->getCurrentSession();
        if (!$session->isStarted()) {
            throw new AgentTokenException(
                'No active session. Please log in to the Neos backend.',
                401,
                'Unauthorized'
            );
        }

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
            throw new AgentTokenException(
                'Failed to generate token: ' . $e->getMessage(),
                500,
                'Internal Server Error',
                $e
            );
        }
    }

    /**
     * Get the encryption key from Flow's HashService for JWT signing.
     *
     * Uses reflection to access the key managed by Flow - no custom secret configuration needed.
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

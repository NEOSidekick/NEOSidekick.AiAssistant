<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Authentication\Provider;

use NEOSidekick\AiAssistant\Security\Authentication\Token\JwtToken;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Neos\Flow\Session\SessionManagerInterface;

/**
 * Authentication provider that validates JWT Bearer tokens.
 *
 * Decodes the JWT, verifies the session is still active, resolves the Neos backend
 * account, and populates the security context for API tool-call endpoints.
 */
class JwtProvider extends AbstractProvider
{
    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected AgentTokenService $agentTokenService;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected SessionManagerInterface $sessionManager;

    /**
     * @Flow\Inject
     * @var AccountRepository
     */
    protected AccountRepository $accountRepository;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    public function getTokenClassNames(): array
    {
        return [JwtToken::class];
    }

    /**
     * @param TokenInterface $authenticationToken
     * @throws UnsupportedAuthenticationTokenException
     */
    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!$authenticationToken instanceof JwtToken) {
            throw new UnsupportedAuthenticationTokenException(
                sprintf(
                    'This provider cannot authenticate the given token. The token must implement %s',
                    JwtToken::class
                ),
                1217339840
            );
        }

        $bearer = $authenticationToken->getBearer();
        if ($bearer === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
            return;
        }

        try {
            $payload = $this->agentTokenService->verifyToken($bearer);
        } catch (\Throwable $e) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $sessionId = $payload['session_id'] ?? null;
        if ($sessionId === null) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $session = $this->sessionManager->getSession($sessionId);
        if ($session === null) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $sub = $payload['sub'] ?? null;
        if ($sub === null || $sub === '') {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $account = null;
        $providerName = $this->options['lookupProviderName'] ?? 'Neos.Neos:Backend';
        $this->securityContext->withoutAuthorizationChecks(function () use ($sub, $providerName, &$account) {
            $account = $this->accountRepository->findActiveByAccountIdentifierAndAuthenticationProviderName(
                $sub,
                $providerName
            );
        });

        if ($account === null) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $account->authenticationAttempted(TokenInterface::AUTHENTICATION_SUCCESSFUL);
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}

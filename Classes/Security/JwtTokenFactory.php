<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Annotations as Flow;
use DateTime;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Context;

/**
 * Class TokenFactory
 *
 * @package RFY\JWT\Domain\Factory
 * @Flow\Scope("singleton")
 */
class JwtTokenFactory
{

    /**
     * @var Context
     * @Flow\Inject
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var AccountRepository
     */
    protected $accountRepository;

    /**
     * @Flow\Inject
     * @var AccountFactory
     */
    protected $accountFactory;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject()
     * @var JwtService
     */
    protected $jwtService;

    /**
     * @return string
     */
    public function getJsonWebToken(): string
    {
        /** @var JwtAccount $account */
        $account = $this->securityContext->getAccount();
        $now = new DateTime();
        $payload['username'] = $account->getAccountIdentifier() ?? $account->getUsername();
        $payload['provider'] = $account->getAuthenticationProviderName();
        $payload['iat'] = $now->getTimestamp();
        $payload['exp'] = (clone $now)->modify('+30 minutes')->getTimestamp();
        return $this->jwtService->createJsonWebToken($payload);
    }
}

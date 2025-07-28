<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Annotations as Flow;
use DateTime;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\AccountFactory;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Context;
use Neos\Flow\Security\Policy\Role;

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
     * Creates a JWT with write permissions for the webhook, using the current user's roles.
     *
     * @return string
     */
    public function createWriteAccessToken(): string
    {
        /** @var JwtAccount $account */
        $account = $this->securityContext->getAccount();
        $roles = array_map(static function (Role $role) {
            return $role->getName();
        }, $account->getRoles());

        return $this->createToken($roles);
    }

    /**
     * Creates a JWT with read-only permissions for the live preview.
     *
     * @return string
     */
    public function createReadOnlyPreviewToken(): string
    {
        $roles = [
            'NEOSidekick.AiAssistant:LivePreview' => 'LivePreview'
        ];

        return $this->createToken($roles);
    }

    /**
     * Private helper to create a token with a given set of roles.
     *
     * @param array $roles The roles to include in the JWT payload.
     * @return string The encoded JSON Web Token.
     */
    private function createToken(array $roles): string
    {
        /** @var JwtAccount $account */
        $account = $this->securityContext->getAccount();
        $now = new DateTime();
        $payload['username'] = $account->getAccountIdentifier();
        $payload['provider'] = $account->getAuthenticationProviderName();
        $payload['roles'] = $roles;
        $payload['iat'] = $now->getTimestamp();
        $payload['exp'] = (clone $now)->modify('+30 minutes')->getTimestamp();
        return $this->jwtService->createJsonWebToken($payload);
    }
}

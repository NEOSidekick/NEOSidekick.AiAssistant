<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Authentication\Provider\AbstractProvider;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\UnsupportedAuthenticationTokenException;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Utility\Now;
use function is_array;

class JwtAuthenticationProvider extends AbstractProvider
{
    /**
     * @Flow\Inject
     * @var PolicyService
     */
    protected $policyService;

    /**
     * @Flow\Inject
     * @var JwtService
     */
    protected $jwtService;

    /**
     * @Flow\Inject
     * @var Now
     */
    protected $now;

    /**
     * @inheritDoc
     */
    public function getTokenClassNames(): array
    {
        return [JwtToken::class];
    }

    /**
     * @inheritDoc
     */
    public function authenticate(TokenInterface $authenticationToken): void
    {
        if (!($authenticationToken instanceof JwtToken)) {
            throw new UnsupportedAuthenticationTokenException('This provider cannot authenticate the given token.', 1417040168);
        }

        /** @var $account Account */
        $account = null;
        $credentials = $authenticationToken->getCredentials();

        if (!is_array($credentials) || !isset($credentials['token'])) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);
            return;
        }

        try {
            $encoded = $authenticationToken->getEncodedJwt();
            $claims = $this->jwtService->decodeJsonWebToken($encoded);
        } catch (\Exception $err) {
            $authenticationToken->setAuthenticationStatus(TokenInterface::WRONG_CREDENTIALS);
            return;
        }

        $account = new JwtAccount();
        $account->setAccountIdentifier($claims->{'username'});
        $account->setClaims($claims);
        $account->setAuthenticationProviderName($claims->{'provider'});
        $roles = (array)$claims->{'roles'};
        foreach (array_keys($roles) as $role) {
            $account->addRole($this->policyService->getRole($role));
        }
        $authenticationToken->setAccount($account);
        $authenticationToken->setAuthenticationStatus(TokenInterface::AUTHENTICATION_SUCCESSFUL);
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Security\Authentication\Token;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Authentication\Token\AbstractToken;
use Neos\Flow\Security\Authentication\Token\SessionlessTokenInterface;
use Neos\Flow\Security\Authentication\TokenInterface;
use Neos\Flow\Security\Exception\AuthenticationRequiredException;
use Neos\Flow\Security\Exception\InvalidAuthenticationStatusException;

/**
 * JWT Bearer token for API authentication.
 *
 * Extracts the JWT from the Authorization: Bearer header. The JwtProvider
 * decodes and validates the token, then populates the security context.
 */
class JwtToken extends AbstractToken implements SessionlessTokenInterface
{
    /**
     * @var array
     * @Flow\Transient
     */
    protected $credentials = ['bearer' => ''];

    /**
     * @param ActionRequest $actionRequest
     * @throws AuthenticationRequiredException
     * @throws InvalidAuthenticationStatusException
     */
    public function updateCredentials(ActionRequest $actionRequest)
    {
        $this->setAuthenticationStatus(self::AUTHENTICATION_NEEDED);
        $httpRequest = $actionRequest->getHttpRequest();

        if (!$httpRequest->hasHeader('Authorization')) {
            return;
        }

        $this->setAuthenticationStatus(TokenInterface::NO_CREDENTIALS_GIVEN);

        foreach ($httpRequest->getHeader('Authorization') as $authorizationHeader) {
            if (str_starts_with($authorizationHeader, 'Bearer ')) {
                $this->credentials['bearer'] = substr($authorizationHeader, strlen('Bearer '));
                $this->setAuthenticationStatus(TokenInterface::AUTHENTICATION_NEEDED);
                return;
            }
        }
    }

    public function getBearer(): string
    {
        return $this->credentials['bearer'] ?? '';
    }

    public function __toString(): string
    {
        return 'JWT Bearer token';
    }
}

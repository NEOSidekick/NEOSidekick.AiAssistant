<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Security\Authentication\Token\AbstractToken;
use Neos\Flow\Security\Authentication\Token\SessionlessTokenInterface;
use Psr\Http\Message\ServerRequestInterface;

class JwtToken extends AbstractToken implements SessionlessTokenInterface
{
    /**
     * @inheritDoc
     */
    public function updateCredentials(ActionRequest $actionRequest)
    {
        $token = $this->getToken($actionRequest->getHttpRequest());

        if (NULL !== $token) {
            $this->credentials['token'] = $token;
            $this->setAuthenticationStatus(self::AUTHENTICATION_NEEDED);
            return;
        }

        $this->setAuthenticationStatus(self::NO_CREDENTIALS_GIVEN);
    }

    /**
     * @return string
     */
    public function getEncodedJwt(): string
    {
        return $this->credentials['token'];
    }

    /**
     * Returns a string representation of the token for logging purposes.
     *
     * @return string The username credential
     */
    public function __toString()
    {
        return 'JWT: "' . \substr($this->credentials['token'], 0, 30) . '..."';
    }

    protected function getToken(ServerRequestInterface $httpRequest): ?string
    {
        if (!$httpRequest->hasHeader('Authorization') && !isset($httpRequest->getQueryParams()['token'])) {
            return null;
        }

        if ($httpRequest->hasHeader('Authorization')) {
            $token = $httpRequest->getHeader('Authorization')[0];
        } else {
            $token = $httpRequest->getQueryParams()['token'];
        }

        if (str_starts_with($token, 'Bearer ')) {
            $token = \substr($token, 7);
        }

        return $token;
    }
}

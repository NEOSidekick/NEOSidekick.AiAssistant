<?php

namespace NEOSidekick\AiAssistant\Security;

use Neos\Flow\Security\Authentication\EntryPointInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class JwtEntryPoint implements EntryPointInterface
{

    /**
     * @inheritDoc
     */
    public function setOptions(array $options)
    {
        // TODO: Implement setOptions() method.
    }

    /**
     * @inheritDoc
     */
    public function getOptions()
    {
        // TODO: Implement getOptions() method.
    }

    /**
     * @inheritDoc
     */
    public function startAuthentication(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(401);
    }
}

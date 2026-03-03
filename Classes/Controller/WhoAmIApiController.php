<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Service\PartyService;

/**
 * JWT-protected endpoint that returns the authenticated user's identity.
 *
 * Useful for verifying that JWT authentication resolves to the correct
 * Neos backend account.
 *
 * @noinspection PhpUnused
 */
class WhoAmIApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var PartyService
     */
    protected PartyService $partyService;

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * @return string JSON with the authenticated account's identity
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function indexAction(): string
    {
        $account = $this->securityContext->getAccount();

        if ($account === null) {
            $this->response->setStatusCode(401);
            return json_encode([
                'authenticated' => false,
            ], JSON_THROW_ON_ERROR);
        }

        $result = [
            'authenticated' => true,
            'account_identifier' => $account->getAccountIdentifier(),
            'authentication_provider' => $account->getAuthenticationProviderName(),
            'roles' => array_map(
                static fn($role) => $role->getIdentifier(),
                $account->getRoles()
            ),
        ];

        $party = $this->partyService->getAssignedPartyOfAccount($account);
        if ($party instanceof User) {
            $result['name'] = $party->getName()->getFullName();
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}

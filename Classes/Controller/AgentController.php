<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Neos\Neos\Service\UserService;

/**
 * Controller for NEOSidekick Agent authorization.
 *
 * Displays an authorization page and handles the authorization flow.
 * Currently returns JSON with token data; future implementation will forward
 * to an external service via GuzzleHttp.
 */
class AgentController extends ActionController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var FusionView
     */
    protected $view;

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
     * @var AgentTokenService
     */
    protected AgentTokenService $agentTokenService;

    /**
     * @param FusionView $view
     *
     * @return void
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/Agent');
    }

    /**
     * Display the authorization page.
     */
    public function indexAction(): void
    {
        // Debug: Log that we reached this method
        error_log('AgentController::indexAction() called');
        
        $user = $this->userService->getBackendUser();
        $this->view->assign('user', $user);
        $this->view->assign('interfaceLanguage', $this->userService->getInterfaceLanguage());
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
        // TODO: Re-instate UriBuilder once route resolution issues are fixed
        // $this->view->assign('authorizeActionUri', $this->uriBuilder->uriFor('authorize', [], 'Agent', 'NEOSidekick.AiAssistant'));
        // Build URI for authorize action - use the route URI directly since UriBuilder has issues
        $this->view->assign('authorizeActionUri', '/neosidekick/agent/do-authorize');
    }

    /**
     * Handle authorization and return token data as JSON.
     *
     * Current implementation: Returns JSON directly (same format as former TokenApiController).
     *
     * Future implementation (when external API is ready):
     * 1. Get token data from AgentTokenService
     * 2. Use GuzzleHttp\Client to POST user_id, account_id, session_id, jwt to external service URL
     * 3. Handle external service response
     * 4. Return appropriate success/error response based on external service
     *
     * @Flow\SkipCsrfProtection
     */
    public function authorizeAction(): string
    {
        $this->response->setContentType('application/json');

        try {
            $tokenData = $this->agentTokenService->generateTokenData();

            // Future: POST to external service using GuzzleHttp
            // $externalServiceUrl = $this->configurationManager->getConfiguration(
            //     \Neos\Flow\Configuration\ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            //     'NEOSidekick.AiAssistant.agent.externalServiceUrl'
            // );
            // if ($externalServiceUrl) {
            //     $client = new \GuzzleHttp\Client();
            //     $response = $client->post($externalServiceUrl, [
            //         'json' => $tokenData,
            //         'headers' => ['Content-Type' => 'application/json'],
            //     ]);
            //     // Handle response, return success/error accordingly
            // }

            return json_encode($tokenData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());
            return json_encode([
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->response->setStatusCode(500);
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => 'Failed to encode response: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }
}

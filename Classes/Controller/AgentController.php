<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
 * POSTs token data to Laravel's OAuth callback endpoint for the agentic chat integration.
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
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @Flow\InjectConfiguration(path="agent.externalServiceUrl")
     * @var string|null
     */
    protected ?string $externalServiceUrl = null;

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
     *
     * @param string|null $state The OAuth state parameter from Laravel (passed through by Neos UI)
     */
    public function indexAction(?string $state = null): void
    {
        $user = $this->userService->getBackendUser();
        $this->view->assign('user', $user);
        $this->view->assign('interfaceLanguage', $this->userService->getInterfaceLanguage());
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
        $this->view->assign('state', $state ?? '');
        $this->view->assign('authorizeActionUri', '/neosidekick/agent/do-authorize');
    }

    /**
     * Handle authorization: generate token data and POST to Laravel callback.
     *
     * Receives state from the form. If externalServiceUrl is configured, POSTs
     * {user_id, account_id, session_id, jwt, state} to Laravel; otherwise returns
     * token data directly (e.g. for development without Laravel).
     *
     * @param string|null $state The OAuth state parameter (Laravel session ID)
     * @return string JSON response
     * @Flow\SkipCsrfProtection
     */
    public function authorizeAction(?string $state = null): string
    {
        $this->response->setContentType('application/json');

        try {
            $tokenData = $this->agentTokenService->generateTokenData();
            $stateValue = $state ?? $this->request->getArgument('state') ?? '';

            if ($this->externalServiceUrl !== null && $this->externalServiceUrl !== '') {
                $payload = [
                    'user_id' => $tokenData['user_id'],
                    'account_id' => $tokenData['account_id'],
                    'session_id' => $tokenData['session_id'],
                    'jwt' => $tokenData['jwt'],
                    'state' => $stateValue,
                ];

                $headers = [
                    'Content-Type' => 'application/json',
                ];
                if (!empty($this->apiKey)) {
                    $headers['Authorization'] = 'Bearer ' . $this->apiKey;
                }

                $client = new Client();
                $response = $client->post($this->externalServiceUrl, [
                    'json' => $payload,
                    'headers' => $headers,
                ]);

                if ($response->getStatusCode() >= 400) {
                    $this->response->setStatusCode($response->getStatusCode());
                    $body = (string) $response->getBody();
                    return $body ?: json_encode([
                        'error' => 'Callback failed',
                        'message' => 'Laravel callback returned ' . $response->getStatusCode(),
                    ], JSON_THROW_ON_ERROR);
                }

                return json_encode([
                    'success' => true,
                    'message' => 'Authorization complete',
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            }

            return json_encode($tokenData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());
            return json_encode([
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->response->setStatusCode(502);
            return json_encode([
                'error' => 'Bad Gateway',
                'message' => 'Failed to reach Laravel callback: ' . $e->getMessage(),
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

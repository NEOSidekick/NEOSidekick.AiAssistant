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
    /**
     * Serves both the HTML consent/completion pages and the JSON authorize/test endpoints.
     * Without application/json, the silent re-auth fetch (Accept: application/json on the
     * .json route) is rejected with 406 Not Acceptable by Flow's content negotiation.
     *
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

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
     * @Flow\InjectConfiguration(path="agent.externalApiDomain")
     * @var string|null
     */
    protected ?string $externalApiDomain = null;

    /**
     * Browser-facing origin the assistant iframe is loaded from (the postMessage opener).
     * Distinct from externalApiDomain, which is only the server-to-server callback URL.
     *
     * @Flow\InjectConfiguration(path="Internal.apiDomain")
     * @var string|null
     */
    protected ?string $apiDomain = null;

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
     * Receives state from the form. If externalApiDomain is configured, POSTs
     * {user_id, account_id, session_id, jwt, state} to Laravel; otherwise returns
     * token data directly (e.g. for development without Laravel).
     *
     * CSRF-protected: callers must send a valid __csrfToken. The first-time consent popup
     * supplies it via the Fusion form (Root.fusion); the silent re-auth flow supplies it from
     * the Neos UI plugin's frontend configuration.
     *
     * @param string|null $state The OAuth state parameter (Laravel session ID)
     * @return string HTML or JSON response
     */
    public function authorizeAction(?string $state = null): string
    {
        $responseFormat = strtolower((string) $this->request->getFormat());
        $wantsJsonResponse = $responseFormat === 'json';

        try {
            $tokenData = $this->agentTokenService->generateTokenData();
            $stateValue = $state ?? $this->request->getArgument('state') ?? '';

            if ($this->externalApiDomain !== null && $this->externalApiDomain !== '') {
                $payload = [
                    'user_id' => $tokenData['user_id'],
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
                $response = $client->post($this->externalApiDomain . '/api/agentic-chat/oauth/callback', [
                    'json' => $payload,
                    'headers' => $headers,
                ]);

                if ($response->getStatusCode() >= 400) {
                    $this->response->setStatusCode($response->getStatusCode());
                    $body = (string) $response->getBody();
                    if ($body !== '') {
                        return $body;
                    }

                    return json_encode([
                        'error' => 'Callback failed',
                        'message' => 'Laravel callback returned ' . $response->getStatusCode(),
                    ], JSON_THROW_ON_ERROR);
                }

                if ($wantsJsonResponse) {
                    $this->response->setContentType('application/json');
                    return json_encode([
                        'success' => true,
                        'message' => 'Authorization complete',
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                }

                $this->response->setContentType('text/html');
                // Target the opener (the embedded assistant iframe) at its explicit browser-facing
                // origin rather than "*", so the completion signal is never delivered to another
                // window. This must be the iframe's apiDomain, not the server-to-server callback URL.
                $openerOrigin = $this->deriveOrigin((string) $this->apiDomain);

                return $this->buildAuthorizationCompleteResponse($openerOrigin);
            }

            $this->response->setContentType('application/json');
            return json_encode($tokenData, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());
            $this->response->setContentType('application/json');
            return json_encode([
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->response->setStatusCode(502);
            $errorMessage = 'Failed to reach Laravel callback: ' . $e->getMessage();

            if ($wantsJsonResponse) {
                $this->response->setContentType('application/json');
                return json_encode([
                    'error' => 'Bad Gateway',
                    'message' => $errorMessage,
                ], JSON_THROW_ON_ERROR);
            }

            $this->response->setContentType('text/html');
            return '<!doctype html><html><head><meta charset="utf-8"><title>Authorization Failed</title></head><body><h1>Authorization failed</h1><p>' . htmlspecialchars($errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
        } catch (JsonException $e) {
            $this->response->setStatusCode(500);
            $this->response->setContentType('application/json');
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => 'Failed to encode response: ' . $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Reduces a configured domain (which may carry a path) to a bare postMessage origin
     * (scheme://host[:port]). Returns null when no trusted HTTP(S) origin can be derived.
     */
    protected function deriveOrigin(string $domain): ?string
    {
        $parts = parse_url($domain);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    protected function buildAuthorizationCompleteResponse(?string $openerOrigin): string
    {
        $postCompletionMessage = '';
        if ($openerOrigin !== null) {
            $encodedOrigin = json_encode($openerOrigin, JSON_THROW_ON_ERROR);
            $postCompletionMessage = 'if(window.opener){window.opener.postMessage({eventName:"neosidekick-agent-authorization-complete"},' . $encodedOrigin . ');}';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Authorization Complete</title></head><body><script>(function(){' . $postCompletionMessage . 'window.close();})();</script><p>Authorization completed. You can close this window.</p></body></html>';
    }

    /**
     * Test the JWT authentication flow by generating a JWT and calling
     * the NodeTypeSchema API endpoint with it via GuzzleHttp.
     *
     * @return string JSON response with the test result
     * @Flow\SkipCsrfProtection
     */
    public function testJwtAction(): string
    {
        $this->response->setContentType('application/json');

        try {
            $tokenData = $this->agentTokenService->generateTokenData();
            $jwt = $tokenData['jwt'];
            $jwtClaims = $this->agentTokenService->verifyToken($jwt);

            $httpRequest = $this->request->getHttpRequest();
            $uri = $httpRequest->getUri();
            $baseUrl = $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() ? ':' . $uri->getPort() : '');

            $client = new Client(['verify' => false]);
            $whoamiResponse = $client->get($baseUrl . '/neosidekick/api/whoami', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $jwt,
                    'Accept' => 'application/json',
                ],
            ]);

            $whoami = json_decode((string) $whoamiResponse->getBody(), true);

            return json_encode([
                'success' => true,
                'token' => [
                    'user_id' => $tokenData['user_id'],
                    'account_id' => $tokenData['account_id'],
                    'session_id' => $tokenData['session_id'],
                ],
                'jwt_claims' => $jwtClaims,
                'authenticated_user' => $whoami,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());
            return json_encode([
                'success' => false,
                'step' => 'token_generation',
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (GuzzleException $e) {
            $responseBody = null;
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $responseBody = (string) $e->getResponse()->getBody();
            }
            $this->response->setStatusCode(502);
            return json_encode([
                'success' => false,
                'step' => 'api_call',
                'error' => 'API call failed',
                'message' => $e->getMessage(),
                'api_response_body' => $responseBody ? mb_substr($responseBody, 0, 2000) : null,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\Throwable $e) {
            $this->response->setStatusCode(500);
            return json_encode([
                'success' => false,
                'step' => 'unknown',
                'error' => get_class($e),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        }
    }
}

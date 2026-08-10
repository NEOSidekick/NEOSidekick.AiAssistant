<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Neos\Neos\Service\UserService;
use Psr\Log\LoggerInterface;

/**
 * Controller for NEOSidekick Agent authorization.
 *
 * Displays an authorization page and handles the authorization flow.
 * POSTs token data to Laravel's OAuth callback endpoint for the agentic chat integration.
 */
class AgentController extends ActionController
{
    protected const REDACTED_JWT_PLACEHOLDER = '[REDACTED_JWT]';

    /**
     * Upper bound for the upstream response body as it goes into the log line. Only the log copy is
     * capped; the redacted body handed back to the client is never truncated.
     */
    protected const MAX_LOGGED_BODY_LENGTH = 2000;

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
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

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
     * CSRF-protected: callers must send a valid token. The first-time consent popup supplies it as
     * the __csrfToken form field (Root.fusion); the silent re-auth flow goes through the Neos UI's
     * fetchWithErrorHandling, which sends it as the X-Flow-Csrftoken header. Flow accepts both.
     *
     * @param string|null $state The OAuth state parameter (Laravel session ID)
     * @return string HTML or JSON response
     */
    public function authorizeAction(?string $state = null): string
    {
        $responseFormat = strtolower((string) $this->request->getFormat());
        $wantsJsonResponse = $responseFormat === 'json';
        $jwt = null;

        try {
            $tokenData = $this->agentTokenService->generateTokenData();
            $jwt = $tokenData['jwt'];
            $stateValue = $this->resolveState($state);

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

                $client = $this->createCallbackClient();
                // Without http_errors=false Guzzle throws on 4xx/5xx, so Laravel errors (e.g. a
                // rejected state) would surface as an opaque 502 instead of the actual response.
                $response = $client->post($this->externalApiDomain . '/api/agentic-chat/oauth/callback', [
                    'json' => $payload,
                    'headers' => $headers,
                    'http_errors' => false,
                ]);

                $upstreamStatus = $response->getStatusCode();

                if ($upstreamStatus >= 400) {
                    $this->response->setStatusCode($this->translateUpstreamStatus($upstreamStatus));
                    // Laravel's response body is the realistic carrier of the JWT back to this side:
                    // an upstream debug/validation page can echo the posted JSON verbatim. Redact it
                    // once, before it reaches either the log or the client.
                    $body = $this->redactJwt((string) $response->getBody(), $jwt);

                    // The log always records the TRUE upstream status, never the translated one, so
                    // an upstream 401 stays diagnosable even though the browser is answered with 502.
                    $logMessage = sprintf(
                        'NEOSidekick agent authorization callback failed with status %d: %s',
                        $upstreamStatus,
                        $this->capForLog($body)
                    );
                    $logContext = LogEnvironment::fromMethodName(__METHOD__);

                    if ($upstreamStatus >= 500) {
                        $this->logger->error($logMessage, $logContext);
                    } else {
                        // 4xx are expected in normal operation (a benign 422 for an already-consumed
                        // state, a 429 from Laravel's rate limiter) and this endpoint is hit once per
                        // editor per poll on a Flow side with no rate limiter and an append-only log,
                        // so they must not be recorded at error level.
                        $this->logger->warning($logMessage, $logContext);
                    }

                    if ($wantsJsonResponse) {
                        $this->response->setContentType('application/json');
                        if ($body !== '') {
                            return $body;
                        }

                        return json_encode([
                            'error' => 'Callback failed',
                            'message' => 'Laravel callback returned ' . $upstreamStatus,
                        ], JSON_THROW_ON_ERROR);
                    }

                    $this->response->setContentType('text/html');
                    // Both formats name the TRUE upstream status in the fallback text for the same
                    // reason the log does: the translated 502 would hide which upstream answer it was.
                    $details = $body !== '' ? $body : 'Laravel callback returned ' . $upstreamStatus;

                    return '<!doctype html><html><head><meta charset="utf-8"><title>Authorization Failed</title></head><body><h1>Authorization failed</h1><p>' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
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
            // With http_errors=false only connect/transfer failures throw, and those messages carry
            // the URL plus cURL's text — not the request body, so no JWT is expected here. The
            // redaction is defense-in-depth against a future Guzzle/middleware change; the realistic
            // carrier is the >=400 response body handled above.
            $errorMessage = 'Failed to reach Laravel callback: ' . $this->redactJwt($e->getMessage(), $jwt);

            $this->logger->error(
                'NEOSidekick agent authorization callback could not be reached: ' . $this->redactJwt($e->getMessage(), $jwt),
                LogEnvironment::fromMethodName(__METHOD__)
            );

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
     * Resolves the OAuth state from the mapped action argument.
     *
     * Flow's dispatcher only passes the argument when the request actually carries it, so
     * `$state === null` is exactly "no state was sent" — there is nothing left to read off the
     * request (`getArgument()` would only throw NoSuchArgumentException for the same case, which
     * used to surface as a 500 for any request that simply omitted the state). An empty state is
     * not fatal (Laravel rejects the callback), but it is always a bug on the calling side, so it
     * is logged.
     */
    protected function resolveState(?string $state): string
    {
        $stateValue = $state ?? '';

        if ($stateValue === '') {
            $this->logger->warning(
                'NEOSidekick agent authorization was requested without a state argument',
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }

        return $stateValue;
    }

    /**
     * Maps an upstream (Laravel) status onto the status this endpoint answers the browser with.
     *
     * Everything is mirrored verbatim except 401, which must never reach the browser from this
     * same-origin Flow endpoint: the Neos UI's `fetchWithErrorHandling` treats ANY 401 as "the Neos
     * backend session expired" — it sets the global request-queue latch and dispatches the
     * authenticationTimeout overlay, parking every subsequent UI request until re-login. A 401 here
     * says nothing about the Neos session; it means Laravel rejected *our* credential (missing or
     * rotated API key, a Basic-auth-protected staging environment). Mirroring it would present a
     * backend-wide session failure on a perfectly healthy session, so it is translated to 502
     * ("the upstream we depend on refused us"), which the latch ignores. The true status is kept in
     * the log line and in the fallback message text.
     */
    protected function translateUpstreamStatus(int $upstreamStatus): int
    {
        return $upstreamStatus === 401 ? 502 : $upstreamStatus;
    }

    /**
     * Caps the log copy of an upstream body. The client-facing (redacted) body stays full — only the
     * log line is bounded, so a verbose upstream error page cannot flood an append-only log file.
     */
    protected function capForLog(string $body): string
    {
        if (mb_strlen($body) <= self::MAX_LOGGED_BODY_LENGTH) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_LOGGED_BODY_LENGTH) . '…[truncated]';
    }

    /**
     * The callback client must never be able to hang the editor's authorization request: without
     * explicit timeouts Guzzle waits indefinitely on an unreachable or stalled Laravel.
     *
     * The budgets are deliberately nested inside the two client-side ones: PHP 4 s total transfer
     * (Guzzle's `timeout` is the whole request, `connect_timeout` a sub-budget of it) < the silent
     * re-authorization's 5 s race in the Neos UI < the 8 s iframe fallback. A Laravel that answers
     * between the PHP and JS budgets would otherwise complete server-side *after* the JS already
     * reported failure, leaving the two sides disagreeing about whether the authorization happened.
     *
     * Trade-off, accepted: the first-time consent popup shares this client, so a Laravel slower
     * than 4 s now fails it with a retryable error page instead of hanging for up to 15 s. Budget
     * coherence is worth more than the rare slow success.
     */
    protected function createCallbackClient(): Client
    {
        return new Client([
            'connect_timeout' => 3,
            'timeout' => 4,
        ]);
    }

    /**
     * Removes the freshly minted JWT from a message before it is logged or handed back to the
     * client. The JWT is a bearer credential for the whole API, so it must not end up in a log file
     * or an error page.
     *
     * The exact-string replacement alone is not enough: Guzzle truncates body summaries at 120
     * characters, so a *prefix* of the token can appear where the full token never does. The regex
     * pass therefore also catches any truncated (or otherwise mangled) JWS-shaped run.
     */
    protected function redactJwt(string $message, ?string $jwt): string
    {
        if ($jwt !== null && $jwt !== '') {
            $message = str_replace($jwt, self::REDACTED_JWT_PLACEHOLDER, $message);
        }

        return (string) preg_replace(
            '/eyJ[A-Za-z0-9_\-.]{20,}/',
            self::REDACTED_JWT_PLACEHOLDER,
            $message
        );
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

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\BackendUserTranslationTrait;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Neos\Neos\Service\UserService;
use Psr\Log\LoggerInterface;

/**
 * Displays the NEOSidekick agent authorization page and POSTs the resulting token data to the
 * NEOSidekick backend's OAuth callback endpoint.
 */
class AgentController extends ActionController
{
    /**
     * Renders the consent page in the backend user's interface language, the way Neos's own
     * backend controllers do; without it every label falls back to Flow's default locale.
     */
    use BackendUserTranslationTrait;

    protected const REDACTED_JWT_PLACEHOLDER = '[REDACTED_JWT]';

    /**
     * Bounds both the log line and the redacted body handed back to the client.
     */
    protected const MAX_LOGGED_BODY_LENGTH = 2000;

    /**
     * Marks a consent flow started by an external MCP client rather than by the chat.
     */
    protected const EXTERNAL_CONSUMER_PREFIX = 'external-';

    /**
     * Bounds the free-text consent values. Laravel sanitized them at client registration, but the
     * consent page is reachable with any hand-crafted query.
     */
    protected const MAX_CONSENT_ARGUMENT_LENGTH = 200;

    /**
     * The consumer value doubles as the refresh family's consumer marker, whose column is 64
     * characters wide - bounding it here keeps the echoed value and the stored marker identical.
     */
    protected const MAX_CONSUMER_MARKER_LENGTH = 64;

    /**
     * Without application/json, Flow's content negotiation answers the silent re-auth fetch with
     * 406 Not Acceptable.
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
     * @var AgentRefreshTokenService
     */
    protected AgentRefreshTokenService $agentRefreshTokenService;

    /**
     * @Flow\Inject
     * @var AgentSigningKeyPushService
     */
    protected AgentSigningKeyPushService $agentSigningKeyPushService;

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
     * The browser-facing origin the assistant iframe is loaded from - distinct from
     * externalApiDomain, which is only the server-to-server callback URL.
     *
     * @Flow\InjectConfiguration(path="Internal.apiDomain")
     * @var string|null
     */
    protected ?string $apiDomain = null;

    /**
     * @param FusionView $view
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/Agent');
    }

    /**
     * Renders the consent screen. A `consumer` starting with the external marker prefix selects the
     * external variant, which names the calling application and where the browser will be sent -
     * both re-sanitized here, because this page answers any hand-crafted query.
     *
     * @param string|null $state The OAuth state parameter, passed through by the Neos UI
     */
    public function indexAction(?string $state = null): void
    {
        $user = $this->userService->getBackendUser();
        $stateValue = $state ?? '';
        $consumerMarker = $this->resolveConsumerMarker();
        $isExternalConsent = $consumerMarker !== '';

        $this->view->assign('userName', $user !== null ? $user->getLabel() : '');
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
        $this->view->assign('state', $stateValue);
        $this->view->assign('authorizeActionUri', '/neosidekick/agent/do-authorize');
        $this->view->assign('isExternalConsent', $isExternalConsent);
        $this->view->assign('consumer', $consumerMarker);
        $this->view->assign(
            'clientName',
            $this->sanitizeForDisplay($this->readConsentArgument('client_name', self::MAX_CONSENT_ARGUMENT_LENGTH))
        );
        $this->view->assign(
            'redirectHost',
            $this->sanitizeForDisplay($this->readConsentArgument('redirect_host', self::MAX_CONSENT_ARGUMENT_LENGTH))
        );
        // A plain navigation, so declining mints nothing and needs no CSRF token.
        $this->view->assign('declineUri', $this->buildReturnLegUri(['state' => $stateValue, 'error' => 'access_denied']));
    }

    /**
     * Generates token data and POSTs it to the backend callback; without a configured
     * externalApiDomain the data is returned directly, for development without a backend.
     *
     * CSRF-protected: the first-time consent popup supplies the token as the __csrfToken form
     * field (Root.fusion), the silent re-auth flow as the X-Flow-Csrftoken header.
     *
     * @param string|null $state The OAuth state parameter (Laravel session ID)
     * @return string HTML or JSON response
     */
    public function authorizeAction(?string $state = null): string
    {
        $responseFormat = strtolower((string) $this->request->getFormat());
        $wantsJsonResponse = $responseFormat === 'json';
        $jwt = null;
        $returnKey = null;

        try {
            $stateValue = $this->resolveState($state);
            $consumerMarker = $this->resolveConsumerMarker();
            $externalApiDomainConfigured = $this->externalApiDomain !== null && $this->externalApiDomain !== '';

            if ($externalApiDomainConfigured) {
                // Before deciding which generation to mint: the push enrolls the key
                // server-side, so a freshly pushed key can mint in this same flow.
                $this->pushSigningKeyOnce();
            }

            // An install that never ran `./flow doctrine:migrate` cannot persist a refresh
            // family at all, so minting new-generation would hand the backend a refresh
            // credential this install can never honour. It stays on the legacy path.
            $mintNewGeneration = $this->agentSigningKeyPushService->isKeyConfirmed()
                && $this->agentRefreshTokenService->isStorageReady();
            $tokenData = $mintNewGeneration
                ? $this->agentTokenService->generateTokenData()
                : $this->agentTokenService->generateLegacyTokenData();
            $jwt = $tokenData['jwt'];

            if ($externalApiDomainConfigured) {
                // Only generated here; committing the family before the callback succeeded
                // would let a benign 422 or a timeout destroy the credential Laravel holds.
                $refreshToken = $mintNewGeneration
                    ? $this->agentRefreshTokenService->generateOpaqueRefreshToken()
                    : null;

                $payload = [
                    'user_id' => $tokenData['user_id'],
                    'session_id' => $tokenData['session_id'],
                    'jwt' => $tokenData['jwt'],
                    'state' => $stateValue,
                ];
                if ($refreshToken !== null) {
                    $payload['refresh_token'] = $refreshToken;
                }
                if ($consumerMarker !== '') {
                    // It travels straight into the return leg, where it proves to Laravel that this
                    // browser is the one that consented.
                    $returnKey = bin2hex(random_bytes(32));
                    $payload['consumer'] = $consumerMarker;
                    $payload['return_key'] = $returnKey;
                }

                $headers = [
                    'Content-Type' => 'application/json',
                ];
                if (!empty($this->apiKey)) {
                    $headers['Authorization'] = 'Bearer ' . $this->apiKey;
                }

                $client = $this->createCallbackClient();
                // Without http_errors=false Guzzle throws on 4xx/5xx, so a rejected state would
                // surface as an opaque 502 instead of the actual response.
                $response = $client->post($this->externalApiDomain . '/api/agentic-chat/oauth/callback', [
                    'json' => $payload,
                    'headers' => $headers,
                    'http_errors' => false,
                ]);

                $upstreamStatus = $response->getStatusCode();

                if ($upstreamStatus >= 400) {
                    $this->response->setStatusCode($this->translateUpstreamStatus($upstreamStatus));
                    // An upstream debug/validation page can echo the posted JSON verbatim, so both
                    // credentials in it are redacted once, before either the log or the client sees
                    // the body. The body is relayed at all because it is the vendor SaaS's own
                    // response and the frontend only reads response.ok.
                    $body = $this->capForLog($this->redactJwt((string) $response->getBody(), [$jwt, $refreshToken, $returnKey]));

                    // The TRUE upstream status, so an upstream 401 stays diagnosable even though
                    // the browser is answered with 502.
                    $logMessage = sprintf(
                        'NEOSidekick agent authorization callback failed with status %d: %s',
                        $upstreamStatus,
                        $body
                    );
                    $logContext = LogEnvironment::fromMethodName(__METHOD__);

                    if ($upstreamStatus >= 500) {
                        $this->logger->error($logMessage, $logContext);
                    } else {
                        // 4xx are routine (an already-consumed state, upstream rate limiting) and
                        // this endpoint is polled per editor into an append-only log.
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
                    // The TRUE upstream status again: the translated 502 would hide which upstream
                    // answer it was.
                    $details = $body !== '' ? $body : 'Laravel callback returned ' . $upstreamStatus;

                    return '<!doctype html><html><head><meta charset="utf-8"><title>Authorization Failed</title></head><body><h1>Authorization failed</h1><p>' . htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
                }

                if ($refreshToken !== null) {
                    // A 200 alone does not mean the presented package was stored: the replay and
                    // lost-write-race branches answer "already_authorized" with stored:false and
                    // discard the jwt and the refresh token. Committing on those would revoke the
                    // very family the platform holds, so this gate is fail-closed - an undecodable
                    // body or a missing status skips the commit and costs at most one failed turn.
                    $decodedBody = json_decode((string) $response->getBody(), true);
                    $upstreamOutcome = is_array($decodedBody) && is_string($decodedBody['status'] ?? null)
                        ? $decodedBody['status']
                        : null;

                    if ($upstreamOutcome === 'authorized') {
                        // Only after the callback stored the package: this revokes every prior
                        // family of the account, so exactly one credential chain per editor
                        // stays live.
                        $this->agentRefreshTokenService->commitRefreshTokenForNewFamily(
                            $refreshToken,
                            $tokenData['account_id'],
                            $tokenData['jti'],
                            $consumerMarker !== ''
                                ? $consumerMarker
                                : AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT
                        );
                    } else {
                        // "already_authorized" is the designed outcome of the two-tab and
                        // silent/popup races, so it is noted rather than warned about.
                        $logLevel = $upstreamOutcome === 'already_authorized' ? 'info' : 'warning';
                        $this->logger->{$logLevel}(
                            sprintf(
                                'NEOSidekick agent authorization callback answered %d without an "authorized" status (%s); the refresh token was not committed.',
                                $upstreamStatus,
                                $upstreamOutcome !== null ? $this->capForLog($upstreamOutcome) : 'no status in body'
                            ),
                            LogEnvironment::fromMethodName(__METHOD__)
                        );
                    }
                }

                if ($returnKey !== null) {
                    // setRedirectUri rather than redirectToUri(): the latter signals by throwing
                    // StopActionException, which the catch-all below would turn into a 500 after
                    // Laravel already accepted the callback.
                    $this->response->setRedirectUri(
                        new Uri($this->buildReturnLegUri(['state' => $stateValue, 'return_key' => $returnKey])),
                        303
                    );

                    return '';
                }

                if ($wantsJsonResponse) {
                    $this->response->setContentType('application/json');
                    return json_encode([
                        'success' => true,
                        'message' => 'Authorization complete',
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                }

                $this->response->setContentType('text/html');
                // An explicit origin rather than "*", so the completion signal is never delivered
                // to another window. The iframe's apiDomain, not the callback URL.
                $openerOrigin = $this->deriveOrigin((string) $this->apiDomain);

                return $this->buildAuthorizationCompleteResponse($openerOrigin);
            }

            // Handed straight to the browser, so it must never carry the 30-day
            // server-to-server refresh credential.
            $this->response->setContentType('application/json');
            return json_encode(
                $tokenData,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            );
        } catch (AgentTokenException $e) {
            $this->response->setStatusCode($e->getStatusCode());
            $this->response->setContentType('application/json');
            return json_encode([
                'error' => $e->getErrorType(),
                'message' => $e->getMessage(),
            ], JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->response->setStatusCode(502);
            // The redaction is defense-in-depth: these messages carry the URL and cURL's text, not
            // the request body. Minted once, so both response formats name the same reference.
            $reference = $this->mintFailureReference(
                'NEOSidekick agent authorization could not reach the Laravel callback: ' . $this->redactJwt($e->getMessage(), $jwt)
            );
            $errorMessage = 'Failed to reach Laravel callback. Reference: ' . $reference;

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
            // The encoder detail says nothing to an editor but is the only clue for an operator.
            $reference = $this->mintFailureReference(
                'NEOSidekick agent authorization could not encode its response: ' . $this->redactJwt($e->getMessage(), $jwt)
            );

            $this->response->setStatusCode(500);
            $this->response->setContentType('application/json');
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => 'Failed to encode response. Reference: ' . $reference,
            ], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // Anything unforeseen must still answer the labeled JSON error shape the Neos UI
            // knows how to read, not a bare Flow 500 page.
            $reference = $this->mintFailureReference(
                'NEOSidekick agent authorization failed unexpectedly: ' . $this->redactJwt($e->getMessage(), $jwt)
            );

            $this->response->setStatusCode(500);
            $this->response->setContentType('application/json');
            // The detail (a DBAL error naming tables, a filesystem path) stays in the log.
            return json_encode([
                'error' => 'Internal Server Error',
                'message' => 'Authorization failed unexpectedly. Reference: ' . $reference,
            ], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Announces the public signing key before the callback carrying a JWT signed with it goes out,
     * so the receiving side already knows the key the token references.
     *
     * Fire-and-forget: an install whose key never arrives keeps the pre-key behavior, because it
     * must never lose the ability to authorize.
     */
    protected function pushSigningKeyOnce(): void
    {
        try {
            $this->agentSigningKeyPushService->pushIfNecessary();
        } catch (\Throwable $throwable) {
            $this->logger->warning(
                'NEOSidekick agent signing key push failed during authorization: ' . $throwable->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * Flow's dispatcher only passes the argument when the request carries it, so a null $state is
     * already the complete answer - reading the raw request again could only throw
     * NoSuchArgumentException, which used to surface as a 500. An empty state is not fatal but is
     * always a bug on the calling side, so it is logged.
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
     * Everything is mirrored verbatim except 401: the Neos UI's `fetchWithErrorHandling` reads any
     * 401 from a same-origin endpoint as "the Neos backend session expired" and parks every
     * subsequent UI request until re-login. An upstream 401 says nothing about the Neos session, so
     * it becomes a 502, which that latch ignores.
     */
    protected function translateUpstreamStatus(int $upstreamStatus): int
    {
        return $upstreamStatus === 401 ? 502 : $upstreamStatus;
    }

    /**
     * Bounds both the append-only log file and the vendor-controlled string rendered into the HTML
     * failure page.
     */
    protected function capForLog(string $body): string
    {
        if (mb_strlen($body) <= self::MAX_LOGGED_BODY_LENGTH) {
            return $body;
        }

        return mb_substr($body, 0, self::MAX_LOGGED_BODY_LENGTH) . '…[truncated]';
    }

    /**
     * Without explicit timeouts Guzzle waits indefinitely and hangs the editor's request.
     *
     * The budgets must stay nested inside the client-side ones: PHP 4 s total transfer < the silent
     * re-authorization's 5 s race in the Neos UI < the 8 s iframe fallback. An upstream answering
     * between the PHP and JS budgets would complete server-side after the JS already reported
     * failure, leaving the two sides disagreeing about whether the authorization happened.
     */
    protected function createCallbackClient(): Client
    {
        return new Client([
            'connect_timeout' => 3,
            'timeout' => 4,
        ]);
    }

    /**
     * Removes minted credentials from a message before it is logged or handed back to the client.
     *
     * The exact-string replacement alone is not enough: Guzzle truncates body summaries at 120
     * characters, so a prefix of the token can appear where the full token never does.
     *
     * No generic 64-hex pattern is added on purpose: the signing key's `kid` is a 64-hex SHA-256
     * too, and it is exactly what key-mismatch diagnostics need to name.
     *
     * $secret is left untyped rather than `string|array|null` because Flow's AOP proxy builder
     * mis-renders a union parameter type in a proxied controller method, emitting a ParseError.
     *
     * @param string|array<int, string|null>|null $secret
     */
    protected function redactJwt(string $message, $secret): string
    {
        foreach (is_array($secret) ? $secret : [$secret] as $value) {
            if (is_string($value) && $value !== '') {
                $message = str_replace($value, self::REDACTED_JWT_PLACEHOLDER, $message);
            }
        }

        return (string) preg_replace(
            '/eyJ[A-Za-z0-9_\-.]{20,}/',
            self::REDACTED_JWT_PLACEHOLDER,
            $message
        );
    }

    /**
     * A generic client message with no correlator is undiagnosable, and these sinks do not pass
     * through AgentTokenService, which mints its own.
     */
    protected function mintFailureReference(string $logMessage): string
    {
        $reference = bin2hex(random_bytes(4));

        $this->logger->error(
            $logMessage . ' (reference ' . $reference . ')',
            LogEnvironment::fromMethodName(__METHOD__)
        );

        return $reference;
    }

    /**
     * Reduces a configured domain to a bare postMessage origin, or null when no trusted HTTP(S)
     * origin can be derived from it.
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

    /**
     * The consumer value of an external consent flow, or an empty string for the chat flow.
     *
     * Bounded to the width of the refresh family's marker column, so the value echoed to Laravel
     * and the value stored as the family scope can never diverge.
     */
    protected function resolveConsumerMarker(): string
    {
        $consumer = $this->readConsentArgument('consumer', self::MAX_CONSUMER_MARKER_LENGTH);
        if (strpos($consumer, self::EXTERNAL_CONSUMER_PREFIX) !== 0) {
            return '';
        }

        return $consumer;
    }

    /**
     * Read straight off the request: these keys are snake_case pass-through values of the consent
     * hand-off, not mapped controller arguments.
     */
    protected function readConsentArgument(string $argumentName, int $maxLength): string
    {
        if (!$this->request->hasArgument($argumentName)) {
            return '';
        }

        $value = $this->request->getArgument($argumentName);
        if (!is_string($value)) {
            return '';
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Strips what a rendered consent string must never carry: C0/C1 control characters and DEL, the
     * soft hyphen, bidi marks, embeddings, overrides and isolates, zero-width and invisible format
     * characters, the BOM, and the line and paragraph separators.
     */
    protected function sanitizeForDisplay(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $sanitized = preg_replace(
            '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{FFF9}-\x{FFFB}]/u',
            '',
            $value
        );

        return trim((string) $sanitized);
    }

    /**
     * The browser-facing Laravel origin - never derived from the request, so a lured consent can
     * not point the return leg anywhere else.
     *
     * @param array<string, string> $query
     */
    protected function buildReturnLegUri(array $query): string
    {
        return rtrim((string) $this->apiDomain, '/')
            . '/oauth/authorized?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
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
}

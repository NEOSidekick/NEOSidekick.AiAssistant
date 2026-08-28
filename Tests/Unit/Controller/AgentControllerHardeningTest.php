<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use NEOSidekick\AiAssistant\Controller\AgentController;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the hardening of the authorization endpoint: no credential may reach a log file or an
 * error page, an unreachable callback must not hang the editor's request, and routine upstream
 * 4xx must not be logged as errors.
 */
class AgentControllerHardeningTest extends TestCase
{
    /**
     * Echoed back by the upstream mock, so the redaction of the second credential is pinned too.
     */
    private const ECHOED_REFRESH_TOKEN = 'abababababababababababababababababababababababababababababababab';

    /**
     * The same 64-hex shape as the refresh token, but not a credential.
     */
    private const ECHOED_KID = 'cdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcdcd';

    /**
     * A realistic JWS: three base64url segments starting with the `eyJ` header marker.
     */
    private const JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJlZGl0b3IiLCJzaWQiOiJzZXNzaW9uLTEifQ.7Hy0hRE1kM6vQ0YlBqB1x5nJ0hMhLQ4lSBoQnGxYzY0';

    /**
     * PHP 4 s total transfer < the Neos UI's 5 s silent-authorization race < the 8 s iframe
     * fallback. Raising them past 5 s brings back the split outcome where the backend completes
     * the authorization after the JS already reported failure.
     *
     * `Client::getConfig()` is removed in Guzzle 8 - this assertion breaks on that upgrade, not
     * the controller.
     *
     * @test
     */
    public function callbackClientCarriesTimeoutsNestedInsideTheClientSideBudgets(): void
    {
        $controller = $this->createController();

        /** @var Client $client */
        $client = $this->invoke($controller, 'createCallbackClient', []);

        self::assertSame(3, $client->getConfig('connect_timeout'));
        self::assertSame(4, $client->getConfig('timeout'));
        self::assertLessThan(5, $client->getConfig('timeout'), 'must stay under the 5s JS silent-authorization race');
    }

    /**
     * @test
     */
    public function resolveStateUsesTheMappedActionArgument(): void
    {
        $controller = $this->createController();

        self::assertSame('laravel-state', $this->invoke($controller, 'resolveState', ['laravel-state']));
    }

    /**
     * getArgument() throws NoSuchArgumentException for an absent argument, which used to surface
     * as a 500 for any request that simply omitted the state.
     *
     * @test
     */
    public function resolveStateReturnsEmptyStringWhenTheArgumentIsAbsent(): void
    {
        $request = $this->createRequestMock();
        $request->expects(self::never())->method('getArgument');
        $request->expects(self::never())->method('hasArgument');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('without a state argument'));

        $controller = $this->createController($request, $logger);

        self::assertSame('', $this->invoke($controller, 'resolveState', [null]));
    }

    /**
     * @test
     */
    public function resolveStateLogsAWarningForAnEmptyState(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $controller = $this->createController(null, $logger);

        self::assertSame('', $this->invoke($controller, 'resolveState', ['']));
    }

    /**
     * @test
     */
    public function redactJwtRemovesTheTokenFromMessages(): void
    {
        $controller = $this->createController();
        $jwt = 'header.payload.signature';

        self::assertSame(
            'cURL error 28 while posting {"jwt":"[REDACTED_JWT]"}',
            $this->invoke($controller, 'redactJwt', ['cURL error 28 while posting {"jwt":"' . $jwt . '"}', $jwt])
        );
    }

    /**
     * Guzzle truncates body summaries at 120 characters, so a prefix of the token turns up where
     * the exact string never does and the str_replace pass alone would let it through.
     *
     * @test
     */
    public function redactJwtRemovesATruncatedToken(): void
    {
        $controller = $this->createController();
        $truncated = mb_substr(self::JWT, 0, 60);

        $redacted = $this->invoke($controller, 'redactJwt', ['response body {"jwt":"' . $truncated . ' (truncated...)', self::JWT]);

        self::assertStringNotContainsString($truncated, $redacted);
        self::assertStringContainsString('[REDACTED_JWT]', $redacted);
    }

    /**
     * @test
     */
    public function redactJwtRemovesAJwtShapedRunWithoutKnowingTheToken(): void
    {
        $controller = $this->createController();

        $redacted = $this->invoke($controller, 'redactJwt', ['leaked ' . self::JWT, null]);

        self::assertSame('leaked [REDACTED_JWT]', $redacted);
    }

    /**
     * @test
     */
    public function redactJwtLeavesMessagesUntouchedWithoutAToken(): void
    {
        $controller = $this->createController();

        self::assertSame('Connection refused', $this->invoke($controller, 'redactJwt', ['Connection refused', null]));
        self::assertSame('Connection refused', $this->invoke($controller, 'redactJwt', ['Connection refused', '']));
    }

    /**
     * Drives the real action end to end, so reverting any one of the hardening steps fails here
     * rather than shipping green.
     *
     * @test
     */
    public function authorizeActionPostsTheResolvedStateAndRedactsTheJwtFromEveryFailureSink(): void
    {
        $recordedUri = null;
        $recordedOptions = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->expects(self::once())
            ->method('post')
            ->willReturnCallback(function (string $uri, array $options) use (&$recordedUri, &$recordedOptions): Response {
                $recordedUri = $uri;
                $recordedOptions = $options;

                // Mirrors an upstream validation page echoing the posted payload back, plus a kid
                // the way a key-mismatch diagnostic names it.
                return new Response(422, [], '{"message":"state already consumed","posted":{"jwt":"' . self::JWT . '","refresh_token":"' . self::ECHOED_REFRESH_TOKEN . '"},"kid":"' . self::ECHOED_KID . '"}');
            });

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$loggedMessages): void {
            $loggedMessages[] = $message;
        });

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response);

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame('https://api.example.test/api/agentic-chat/oauth/callback', $recordedUri);
        self::assertSame('laravel-state', $recordedOptions['json']['state']);
        self::assertSame(self::JWT, $recordedOptions['json']['jwt']);
        self::assertFalse($recordedOptions['http_errors']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringNotContainsString(self::JWT, $body);
        self::assertStringContainsString('[REDACTED_JWT]', $body);
        self::assertStringContainsString('state already consumed', $body);

        // The refresh token is a 30-day server-to-server credential.
        self::assertStringNotContainsString(self::ECHOED_REFRESH_TOKEN, $body);

        // The kid MUST survive: it is a 64-hex SHA-256 like the refresh token but not a
        // credential, and a key-mismatch diagnosis needs it.
        self::assertStringContainsString(self::ECHOED_KID, $body);

        self::assertCount(1, $loggedMessages);
        self::assertStringNotContainsString(self::JWT, $loggedMessages[0]);
        self::assertStringContainsString('[REDACTED_JWT]', $loggedMessages[0]);
        // The redaction happens once, before either sink.
        self::assertStringNotContainsString(self::ECHOED_REFRESH_TOKEN, $loggedMessages[0]);
    }

    /**
     * An absent state must reach the backend as an empty string, never as null.
     *
     * @test
     */
    public function authorizeActionResolvesAnAbsentStateToAnEmptyStringAndLogsIt(): void
    {
        $recordedOptions = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedOptions): Response {
                $recordedOptions = $options;

                return new Response(200, [], '{"status":"authorized"}');
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('without a state argument'));

        $controller = $this->createAuthorizeController($client, $logger, new ActionResponse());

        $controller->authorizeAction(null);

        self::assertSame('', $recordedOptions['json']['state']);
    }

    /**
     * The HTML sink is the one an editor actually sees.
     *
     * @test
     */
    public function authorizeActionRedactsTheJwtFromTheRenderedErrorPage(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(
            new Response(422, [], '{"posted":{"jwt":"' . self::JWT . '"}}')
        );

        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), new ActionResponse(), 'html');

        $body = $controller->authorizeAction('laravel-state');

        self::assertStringContainsString('Authorization failed', $body);
        self::assertStringNotContainsString(self::JWT, $body);
        self::assertStringContainsString('[REDACTED_JWT]', $body);
    }

    /**
     * Upstream rate limiting and a benign 422 are routine, and this endpoint is polled per editor
     * into an append-only log with no limiter on the Flow side.
     *
     * @test
     */
    public function authorizeActionLogsFourHundredsAsWarningAndFiveHundredsAsError(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(503, [], 'upstream unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('status 503'));
        $logger->expects(self::never())->method('warning');

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response);

        $controller->authorizeAction('laravel-state');

        self::assertSame(503, $response->getStatusCode());
    }

    /**
     * The Neos UI's fetchWithErrorHandling reads any 401 from a same-origin endpoint as "the Neos
     * session expired" and parks the whole backend UI. An upstream 401 says nothing about the Neos
     * session, so mirroring it would do that on a healthy session.
     *
     * @test
     */
    public function authorizeActionAnswersAnUpstreamUnauthorizedWithBadGatewayInsteadOfMirroringIt(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(
            new Response(401, [], '{"message":"Unauthenticated.","posted":{"jwt":"' . self::JWT . '"}}')
        );

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$loggedMessages): void {
            $loggedMessages[] = $message;
        });

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response);

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame(502, $response->getStatusCode(), 'a 401 must never reach the Neos UI latch');

        self::assertStringContainsString('Unauthenticated.', $body);
        self::assertStringNotContainsString(self::JWT, $body);
        self::assertStringContainsString('[REDACTED_JWT]', $body);

        self::assertCount(1, $loggedMessages);
        self::assertStringContainsString('status 401', $loggedMessages[0]);
        self::assertStringNotContainsString(self::JWT, $loggedMessages[0]);
    }

    /**
     * The popup's empty-body fallback text must still name the true upstream status.
     *
     * @test
     */
    public function authorizeActionTranslatesUpstreamUnauthorizedForTheHtmlFormatToo(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(401, [], ''));

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), $response, 'html');

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Authorization failed', $body);
        self::assertStringContainsString('Laravel callback returned 401', $body);
    }

    /**
     * The translation is a targeted exception for the one status the Neos UI reinterprets
     * globally, not a blanket rewrite.
     *
     * @test
     */
    public function authorizeActionStillMirrorsEveryOtherUpstreamStatus(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(403, [], 'forbidden'));

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), $response);

        $controller->authorizeAction('laravel-state');

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The cap bounds both the append-only log and the vendor-sized body reaching the editor.
     *
     * @test
     */
    public function authorizeActionCapsTheUpstreamBodyForTheLogAndForTheClient(): void
    {
        $longBody = str_repeat('x', 5000);

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(500, [], $longBody));

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string $message) use (&$loggedMessages): void {
            $loggedMessages[] = $message;
        });

        $controller = $this->createAuthorizeController($client, $logger, new ActionResponse());

        $body = $controller->authorizeAction('laravel-state');

        self::assertCount(1, $loggedMessages);
        self::assertStringContainsString('…[truncated]', $loggedMessages[0]);
        self::assertSame(2000, mb_substr_count($loggedMessages[0], 'x'));

        self::assertStringContainsString('…[truncated]', $body, 'the client-facing body must be capped too');
        self::assertSame(2000, mb_substr_count($body, 'x'));
    }

    /**
     * The family is committed only after the callback succeeded, paired via jti and pinned to the
     * account UUID.
     *
     * @test
     */
    public function authorizeActionSendsTheRefreshTokenAndCommitsTheFamilyOnlyAfterTheCallbackSucceeded(): void
    {
        $callOrder = [];
        $recordedOptions = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedOptions, &$callOrder): Response {
                $recordedOptions = $options;
                $callOrder[] = 'callback';

                return new Response(200, [], '{"status":"authorized"}');
            }
        );

        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), new ActionResponse());

        $refreshTokenService = $this->createRefreshTokenServiceMock(str_repeat('cd', 32));
        $refreshTokenService->expects(self::once())
            ->method('commitRefreshTokenForNewFamily')
            ->with(str_repeat('cd', 32), 'editor-account', 'jti-1')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'commit';
            });
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $controller->authorizeAction('laravel-state');

        self::assertSame(str_repeat('cd', 32), $recordedOptions['json']['refresh_token']);
        self::assertSame(self::JWT, $recordedOptions['json']['jwt']);
        self::assertSame(['callback', 'commit'], $callOrder, 'the family must only be committed after Laravel accepted the callback');
    }

    /**
     * Two tabs share one state, so the loser's callback is answered 200 by the replay or
     * lost-race branch, which stores nothing. Committing there would revoke the family the
     * platform actually holds, so the gate is fail-closed on anything but "authorized".
     *
     * @dataProvider nonAuthorizedCallbackBodies
     *
     * @test
     */
    public function aTwoHundredWhoseBodyIsNotAuthorizedCommitsNothing(string $responseBody, string $expectedLogLevel): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(200, [], $responseBody));

        // A designed race outcome is noted; anything unexpected is warned about.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method($expectedLogLevel)
            ->with(self::stringContains('was not committed'));
        $logger->expects(self::never())->method($expectedLogLevel === 'info' ? 'warning' : 'info');

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response);

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $body = $controller->authorizeAction('laravel-state');

        // The editor's flow is untouched: only the credential commit is skipped.
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Authorization complete', $body);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nonAuthorizedCallbackBodies(): array
    {
        return [
            'already authorized replay' => ['{"status":"already_authorized","stored":false}', 'info'],
            'missing status' => ['{"stored":true}', 'warning'],
            'unknown status' => ['{"status":"queued"}', 'warning'],
            'non-json body' => ['<!doctype html><html><body>OK</body></html>', 'warning'],
        ];
    }

    /**
     * A benign 422 used to arrive after the new family had been persisted and every prior family
     * revoked, leaving the backend holding a revoked token whose next renewal hits the
     * replay-theft branch.
     *
     * @test
     */
    public function aFailedCallbackNeverCommitsTheRefreshTokenFamily(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(422, [], '{"message":"state already consumed"}'));

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), $response);

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $controller->authorizeAction('laravel-state');

        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * Same guarantee for the transport-level failure.
     *
     * @test
     */
    public function anUnreachableCallbackNeverCommitsTheRefreshTokenFamily(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willThrowException(
            new ConnectException('cURL error 28: timeout', new Request('POST', 'https://api.example.test'))
        );

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $this->createMock(LoggerInterface::class), $response);

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $controller->authorizeAction('laravel-state');

        self::assertSame(502, $response->getStatusCode());
    }

    /**
     * Pending equals full legacy behavior: no RS256 mint, no refresh family, no refresh_token
     * field in the callback payload.
     *
     * @test
     */
    public function aPendingSigningKeyMintsLegacyTokensWithoutARefreshToken(): void
    {
        $recordedOptions = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedOptions): Response {
                $recordedOptions = $options;

                return new Response(200, [], '{"status":"authorized"}');
            }
        );

        $controller = $this->createAuthorizeController(
            $client,
            $this->createMock(LoggerInterface::class),
            new ActionResponse(),
            'json',
            $this->createSilentSigningKeyPushService(false)
        );

        $tokenService = $this->createTokenServiceMock();
        $tokenService->expects(self::never())->method('generateTokenData');
        $tokenService->expects(self::once())->method('generateLegacyTokenData');
        $this->setProperty($controller, 'agentTokenService', $tokenService);

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('generateOpaqueRefreshToken');
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $controller->authorizeAction('laravel-state');

        self::assertIsArray($recordedOptions['json']);
        self::assertArrayNotHasKey('refresh_token', $recordedOptions['json']);
        self::assertSame(self::JWT, $recordedOptions['json']['jwt']);
    }

    /**
     * Both halves of the re-push decision ride pushIfNecessary, so only the fact that authorize
     * always consults it is pinned here.
     *
     * @test
     */
    public function authorizeActionAlwaysConsultsThePushServiceForRePushes(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(200, [], '{"status":"authorized"}'));

        $pushService = $this->getMockBuilder(AgentSigningKeyPushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushIfNecessary', 'isKeyConfirmed'])
            ->getMock();
        $pushService->expects(self::once())->method('pushIfNecessary')->willReturn(null);
        $pushService->method('isKeyConfirmed')->willReturn(false);

        $controller = $this->createAuthorizeController(
            $client,
            $this->createMock(LoggerInterface::class),
            new ActionResponse(),
            'json',
            $pushService
        );

        $controller->authorizeAction('laravel-state');
    }

    /**
     * The development fallback hands the response to the browser, which must never carry the
     * 30-day server-to-server refresh credential.
     *
     * @test
     */
    public function theDevelopmentFallbackNeverCreatesOrReturnsARefreshToken(): void
    {
        $controller = $this->getMockBuilder(AgentController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createCallbackClient'])
            ->getMock();
        $controller->expects(self::never())->method('createCallbackClient');

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('generateOpaqueRefreshToken');
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');

        $this->setProperty($controller, 'logger', $this->createMock(LoggerInterface::class));
        $this->setProperty($controller, 'request', $this->createRequestMock());
        $this->setProperty($controller, 'response', new ActionResponse());
        $this->setProperty($controller, 'agentTokenService', $this->createTokenServiceMock());
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);
        $this->setProperty($controller, 'agentSigningKeyPushService', $this->createSilentSigningKeyPushService());
        $this->setProperty($controller, 'externalApiDomain', '');
        $this->setProperty($controller, 'apiDomain', 'https://app.example.test');
        $this->setProperty($controller, 'apiKey', 'the-api-key');

        $body = $controller->authorizeAction('laravel-state');

        $decodedBody = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('refresh_token', $decodedBody);
        self::assertSame(self::JWT, $decodedBody['jwt']);
    }

    /**
     * The receiving side must already know the key before a JWT signed with it arrives.
     *
     * @test
     */
    public function authorizeActionAnnouncesTheSigningKeyBeforeItPostsTheCallback(): void
    {
        $callOrder = [];
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(function () use (&$callOrder): Response {
            $callOrder[] = 'callback';

            return new Response(200, [], '{"status":"authorized"}');
        });

        $pushService = $this->getMockBuilder(AgentSigningKeyPushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushIfNecessary', 'isKeyConfirmed'])
            ->getMock();
        $pushService->method('isKeyConfirmed')->willReturn(true);
        $pushService->expects(self::once())->method('pushIfNecessary')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'key-push';

            return null;
        });

        $controller = $this->createAuthorizeController(
            $client,
            $this->createMock(LoggerInterface::class),
            new ActionResponse(),
            'json',
            $pushService
        );

        $controller->authorizeAction('laravel-state');

        self::assertSame(['key-push', 'callback'], $callOrder);
    }

    /**
     * An install whose key never arrives must never lose the ability to authorize.
     *
     * @test
     */
    public function aFailingSigningKeyPushNeverBreaksTheAuthorization(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(200, [], '{"status":"authorized"}'));

        $pushService = $this->getMockBuilder(AgentSigningKeyPushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushIfNecessary', 'isKeyConfirmed'])
            ->getMock();
        $pushService->method('isKeyConfirmed')->willReturn(true);
        $pushService->method('pushIfNecessary')->willThrowException(new \RuntimeException('key storage is read-only'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('signing key push failed'));

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response, 'json', $pushService);

        $body = $controller->authorizeAction('laravel-state');

        self::assertStringContainsString('Authorization complete', $body);
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * An installation that never ran `./flow doctrine:migrate` cannot persist a refresh family at
     * all, so the backend must never be handed a credential it can never honour.
     *
     * @test
     */
    public function anInstallWithoutTheRefreshTokenStorageMintsLegacyTokensWithoutARefreshToken(): void
    {
        $recordedOptions = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedOptions): Response {
                $recordedOptions = $options;

                return new Response(200, [], '{"status":"authorized"}');
            }
        );

        $controller = $this->createAuthorizeController(
            $client,
            $this->createMock(LoggerInterface::class),
            new ActionResponse(),
            'json',
            $this->createSilentSigningKeyPushService(true)
        );

        $tokenService = $this->createTokenServiceMock();
        $tokenService->expects(self::never())->method('generateTokenData');
        $tokenService->expects(self::once())->method('generateLegacyTokenData');
        $this->setProperty($controller, 'agentTokenService', $tokenService);

        $refreshTokenService = $this->createRefreshTokenServiceMock(
            'abababababababababababababababababababababababababababababababab',
            false
        );
        $refreshTokenService->expects(self::never())->method('generateOpaqueRefreshToken');
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $controller->authorizeAction('laravel-state');

        self::assertIsArray($recordedOptions['json']);
        self::assertArrayNotHasKey('refresh_token', $recordedOptions['json']);
    }

    /**
     * A residual failure must still produce the labeled JSON error shape the Neos UI can read,
     * not a bare Flow 500 page.
     *
     * The detail is not relayed: a DBAL error names tables and connection details, and this
     * endpoint answers an editor's browser. The reference id in both is the only correlator.
     *
     * @test
     */
    public function anUnexpectedFailureIsAnsweredWithTheLabeledErrorShapeAndLogged(): void
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturn(new Response(200, [], '{"status":"authorized"}'));

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->willReturnCallback(
            function (string $message) use (&$loggedMessages): void {
                $loggedMessages[] = $message;
            }
        );

        $response = new ActionResponse();
        $controller = $this->createAuthorizeController($client, $logger, $response, 'json');

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->method('commitRefreshTokenForNewFamily')
            ->willThrowException(new \RuntimeException('Base table or view not found: agentrefreshtokenrecord'));
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame(500, $response->getStatusCode());
        $decodedBody = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('Internal Server Error', $decodedBody['error']);
        self::assertStringNotContainsString('Base table or view not found', $decodedBody['message']);

        self::assertCount(1, $loggedMessages);
        self::assertStringContainsString('failed unexpectedly', $loggedMessages[0]);
        self::assertStringContainsString('Base table or view not found', $loggedMessages[0]);

        self::assertSame(1, preg_match('/Reference: ([0-9a-f]{8})/', $decodedBody['message'], $matches),
            'the client message must carry a reference id to quote to support');
        self::assertStringContainsString($matches[1], $loggedMessages[0],
            'the same reference id must appear in the log line the detail went to');
    }

    /**
     * @return AgentSigningKeyPushService&MockObject
     */
    private function createSilentSigningKeyPushService(bool $keyConfirmed = true)
    {
        $pushService = $this->getMockBuilder(AgentSigningKeyPushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushIfNecessary', 'isKeyConfirmed'])
            ->getMock();
        $pushService->method('pushIfNecessary')->willReturn(null);
        $pushService->method('isKeyConfirmed')->willReturn($keyConfirmed);

        return $pushService;
    }

    /**
     * @return AgentTokenService&MockObject
     */
    private function createTokenServiceMock()
    {
        $tokenService = $this->getMockBuilder(AgentTokenService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateTokenData', 'generateLegacyTokenData'])
            ->getMock();
        $tokenService->method('generateTokenData')->willReturn([
            'user_id' => 'editor',
            'account_id' => 'editor-account',
            'session_id' => 'session-1',
            'jwt' => self::JWT,
            'jti' => 'jti-1',
        ]);
        $tokenService->method('generateLegacyTokenData')->willReturn([
            'user_id' => 'editor',
            'account_id' => 'editor-account',
            'session_id' => 'session-1',
            'jwt' => self::JWT,
        ]);

        return $tokenService;
    }

    /**
     * @return AgentRefreshTokenService&MockObject
     */
    private function createRefreshTokenServiceMock(
        string $opaqueToken = 'abababababababababababababababababababababababababababababababab',
        bool $storageReady = true
    ) {
        $refreshTokenService = $this->getMockBuilder(AgentRefreshTokenService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateOpaqueRefreshToken', 'commitRefreshTokenForNewFamily', 'isStorageReady'])
            ->getMock();
        $refreshTokenService->method('generateOpaqueRefreshToken')->willReturn($opaqueToken);
        $refreshTokenService->method('isStorageReady')->willReturn($storageReady);

        return $refreshTokenService;
    }

    /**
     * @return AgentController&MockObject
     */
    private function createAuthorizeController(
        Client $client,
        LoggerInterface $logger,
        ActionResponse $response,
        string $format = 'json',
        ?AgentSigningKeyPushService $signingKeyPushService = null
    ) {
        $controller = $this->getMockBuilder(AgentController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createCallbackClient'])
            ->getMock();
        $controller->expects(self::once())->method('createCallbackClient')->willReturn($client);

        $this->setProperty($controller, 'logger', $logger);
        $this->setProperty($controller, 'request', $this->createRequestMock($format));
        $this->setProperty($controller, 'response', $response);
        $this->setProperty($controller, 'agentTokenService', $this->createTokenServiceMock());
        $this->setProperty($controller, 'agentRefreshTokenService', $this->createRefreshTokenServiceMock());
        $this->setProperty($controller, 'agentSigningKeyPushService', $signingKeyPushService ?? $this->createSilentSigningKeyPushService());
        $this->setProperty($controller, 'externalApiDomain', 'https://api.example.test');
        $this->setProperty($controller, 'apiDomain', 'https://app.example.test');
        $this->setProperty($controller, 'apiKey', 'the-api-key');

        return $controller;
    }

    private function createController(?ActionRequest $request = null, ?LoggerInterface $logger = null): AgentController
    {
        $controller = (new ReflectionClass(AgentController::class))->newInstanceWithoutConstructor();

        $this->setProperty($controller, 'logger', $logger ?? $this->createMock(LoggerInterface::class));
        if ($request !== null) {
            $this->setProperty($controller, 'request', $request);
        }

        return $controller;
    }

    /**
     * @return ActionRequest&MockObject
     */
    private function createRequestMock(string $format = 'json')
    {
        $request = $this->getMockBuilder(ActionRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasArgument', 'getArgument', 'getFormat'])
            ->getMock();
        $request->method('getFormat')->willReturn($format);

        return $request;
    }

    private function setProperty(AgentController $controller, string $propertyName, mixed $value): void
    {
        $property = (new ReflectionClass(AgentController::class))->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }

    /**
     * @param array<mixed> $arguments
     */
    private function invoke(AgentController $controller, string $methodName, array $arguments): mixed
    {
        $method = new ReflectionMethod(AgentController::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($controller, $arguments);
    }
}

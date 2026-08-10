<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use NEOSidekick\AiAssistant\Controller\AgentController;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Guards the hardening of the authorization endpoint: a missing state must not blow up with a 500,
 * an unreachable callback must not hang the editor's request forever, no JWT may ever reach a log
 * file or an error page, and routine 4xx answers from Laravel must not be logged as errors.
 */
class AgentControllerHardeningTest extends TestCase
{
    /**
     * A realistic JWS: three base64url segments, the first starting with the `eyJ` marker of a
     * base64-encoded JSON header.
     */
    private const JWT = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJlZGl0b3IiLCJzaWQiOiJzZXNzaW9uLTEifQ.7Hy0hRE1kM6vQ0YlBqB1x5nJ0hMhLQ4lSBoQnGxYzY0';

    /**
     * The budgets must stay nested inside the client-side ones: PHP 4 s total transfer < the Neos
     * UI's 5 s silent-authorization race < the 8 s iframe fallback. Raising them past 5 s brings
     * back the split outcome where Laravel completes the authorization after the JS already
     * reported failure.
     *
     * Note: `Client::getConfig()` is deprecated and removed in Guzzle 8 — this assertion is what
     * will break on that upgrade, not the controller.
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
     * The regression this guards: getArgument() throws NoSuchArgumentException for an absent
     * argument, which used to surface as a 500 for any request that simply omitted the state.
     *
     * Flow's dispatcher only passes the argument when the request carries it, so a null $state is
     * already the complete answer — reading the raw request again can only throw. The `never()`
     * expectation pins that the endpoint answers without touching it.
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
     * Guzzle truncates body summaries at 120 characters, so a *prefix* of the token turns up where
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
     * Drives the real action end to end (only the client factory is stubbed), so reverting any one
     * of the hardening steps — the timeout-carrying factory, the redaction, the state resolution —
     * fails here rather than shipping green.
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

                // Mirrors an upstream validation/debug page echoing the posted payload back.
                return new Response(422, [], '{"message":"state already consumed","posted":{"jwt":"' . self::JWT . '"}}');
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

        self::assertCount(1, $loggedMessages);
        self::assertStringNotContainsString(self::JWT, $loggedMessages[0]);
        self::assertStringContainsString('[REDACTED_JWT]', $loggedMessages[0]);
    }

    /**
     * Pins that the action goes through resolveState instead of forwarding the raw argument: an
     * absent state must reach Laravel as an empty string (never as null) and must be logged.
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

                return new Response(200, [], '{"ok":true}');
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
     * The HTML sink is the one an editor actually sees; it must not embed the credential either.
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
     * A 429 from Laravel's rate limiter or a benign 422 is routine, and this endpoint is polled per
     * editor on a Flow side with no limiter and an append-only log.
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
     * The Neos UI's fetchWithErrorHandling reads ANY 401 from a same-origin endpoint as "the Neos
     * session expired": it sets the global request-queue latch and dispatches the
     * authenticationTimeout overlay. An upstream 401 (rotated API key, Basic-auth-protected
     * staging) says nothing about the Neos session, so mirroring it would park the whole backend
     * UI on a healthy session. It must be translated to 502 — while the log keeps the true status.
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
     * The HTML sink (the popup) must translate the status identically — and its empty-body fallback
     * text must still name the true upstream status, not the translated one.
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
     * Every other status stays mirrored verbatim — the translation is a targeted exception for the
     * one status the Neos UI reinterprets globally, not a blanket rewrite.
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
     * A verbose upstream error page must not flood Flow's append-only log. Only the log copy is
     * bounded; the client-facing body stays complete.
     *
     * @test
     */
    public function authorizeActionCapsTheLoggedBodyButNotTheClientFacingOne(): void
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

        self::assertSame($longBody, $body, 'the client-facing body must not be truncated');
    }

    /**
     * @return AgentController&MockObject
     */
    private function createAuthorizeController(
        Client $client,
        LoggerInterface $logger,
        ActionResponse $response,
        string $format = 'json'
    ) {
        $controller = $this->getMockBuilder(AgentController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createCallbackClient'])
            ->getMock();
        $controller->expects(self::once())->method('createCallbackClient')->willReturn($client);

        $tokenService = $this->getMockBuilder(AgentTokenService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateTokenData'])
            ->getMock();
        $tokenService->method('generateTokenData')->willReturn([
            'user_id' => 'editor',
            'account_id' => 'editor-account',
            'session_id' => 'session-1',
            'jwt' => self::JWT,
        ]);

        $this->setProperty($controller, 'logger', $logger);
        $this->setProperty($controller, 'request', $this->createRequestMock($format));
        $this->setProperty($controller, 'response', $response);
        $this->setProperty($controller, 'agentTokenService', $tokenService);
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

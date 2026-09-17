<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use NEOSidekick\AiAssistant\Controller\AgentController;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The external MCP return leg of authorizeAction.
 *
 * The callback payload asserted here is the same wire contract Laravel's WithExternalMcpOAuthFlow
 * test concern posts from the other side - a change to either must change both.
 */
class AgentControllerExternalConsentTest extends TestCase
{
    /**
     * The browser-facing Laravel origin (Internal.apiDomain), which the return leg must use - never
     * the server-to-server callback domain.
     */
    private const API_DOMAIN = 'https://app.example.test';

    private const CALLBACK_DOMAIN = 'http://laravel.internal.test';

    private const CONSUMER = 'external-9f1c3d2e';

    private const JWT = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJlZGl0b3IifQ.c2lnbmF0dXJl';

    /**
     * The whole external return leg: the callback carries the echoed marker and a fresh 64-hex
     * return key, the refresh family is committed under that marker, and the browser is sent back
     * to the browser-facing origin with the very same key.
     *
     * @test
     */
    public function anAcceptedExternalCallbackCommitsTheMarkedFamilyAndRedirectsBackWithTheReturnKey(): void
    {
        $recordedPayload = null;
        $response = new ActionResponse();
        $controller = $this->createAuthorizeController(
            $this->createCallbackClientMock(new Response(200, [], '{"status":"authorized"}'), $recordedPayload),
            $response,
            'html',
            ['consumer' => self::CONSUMER]
        );

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::once())
            ->method('commitRefreshTokenForNewFamily')
            ->with(self::anything(), 'editor-account', 'jti-1', self::CONSUMER);
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame(self::CONSUMER, $recordedPayload['consumer']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $recordedPayload['return_key']);
        self::assertSame('laravel-state', $recordedPayload['state']);
        self::assertSame(self::JWT, $recordedPayload['jwt']);

        self::assertSame('', $body, 'the redirect must not render a page body');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            self::API_DOMAIN . '/oauth/authorized?state=laravel-state&return_key=' . $recordedPayload['return_key'],
            (string)$response->getRedirectUri()
        );
    }

    /**
     * A consent URL stripped of the consumer takes the chat path: no return key is sent, the family
     * stays chat-marked, and the popup keeps its postMessage completion page. Laravel's external
     * arm then refuses the callback with external_return_key_invalid - fail-closed.
     *
     * @test
     */
    public function aFlowWithoutAMarkerKeepsThePostMessagePageAndTheChatFamily(): void
    {
        $recordedPayload = null;
        $response = new ActionResponse();
        $controller = $this->createAuthorizeController(
            $this->createCallbackClientMock(new Response(200, [], '{"status":"authorized"}'), $recordedPayload),
            $response,
            'html'
        );

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::once())
            ->method('commitRefreshTokenForNewFamily')
            ->with(self::anything(), 'editor-account', 'jti-1', AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT);
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $body = $controller->authorizeAction('laravel-state');

        self::assertArrayNotHasKey('return_key', $recordedPayload);
        self::assertArrayNotHasKey('consumer', $recordedPayload);
        self::assertNull($response->getRedirectUri());
        self::assertStringContainsString('neosidekick-agent-authorization-complete', $body);
    }

    /**
     * A consumer that is not an external marker is not a marker at all. One that is a marker doubles
     * as the refresh family's consumer marker, whose column is 64 characters wide: the echoed value
     * and the stored marker must not be able to diverge.
     *
     * @test
     */
    public function aConsumerWithoutTheExternalPrefixIsNotTreatedAsAMarker(): void
    {
        $recordedPayload = null;
        $response = new ActionResponse();
        $controller = $this->createAuthorizeController(
            $this->createCallbackClientMock(new Response(200, [], '{"status":"authorized"}'), $recordedPayload),
            $response,
            'html',
            ['consumer' => 'chat']
        );

        $controller->authorizeAction('laravel-state');

        self::assertArrayNotHasKey('return_key', $recordedPayload);
        self::assertNull($response->getRedirectUri());

        $overlongPayload = null;
        $overlongConsumer = 'external-' . str_repeat('a', 200);
        $overlongController = $this->createAuthorizeController(
            $this->createCallbackClientMock(new Response(200, [], '{"status":"authorized"}'), $overlongPayload),
            new ActionResponse(),
            'html',
            ['consumer' => $overlongConsumer]
        );

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::once())
            ->method('commitRefreshTokenForNewFamily')
            ->with(self::anything(), 'editor-account', 'jti-1', mb_substr($overlongConsumer, 0, 64));
        $this->setProperty($overlongController, 'agentRefreshTokenService', $refreshTokenService);

        $overlongController->authorizeAction('laravel-state');

        self::assertSame(mb_substr($overlongConsumer, 0, 64), $overlongPayload['consumer']);
    }

    /**
     * A refused callback must leave nothing behind: no family, and above all no return leg, because
     * Laravel never stored the key it would carry.
     *
     * @test
     */
    public function aRefusedExternalCallbackNeitherRedirectsNorCommitsAFamily(): void
    {
        $recordedPayload = null;
        $response = new ActionResponse();
        $controller = $this->createAuthorizeController(
            $this->createCallbackClientMock(
                new Response(422, [], '{"message":"external_return_key_invalid"}'),
                $recordedPayload
            ),
            $response,
            'html',
            ['consumer' => self::CONSUMER]
        );

        $refreshTokenService = $this->createRefreshTokenServiceMock();
        $refreshTokenService->expects(self::never())->method('commitRefreshTokenForNewFamily');
        $this->setProperty($controller, 'agentRefreshTokenService', $refreshTokenService);

        $body = $controller->authorizeAction('laravel-state');

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($response->getRedirectUri());
        self::assertStringContainsString('Authorization failed', $body);
    }

    /**
     * An upstream debug page can echo the posted payload back, and that body is relayed to the
     * editor and written to the log.
     *
     * @test
     */
    public function anEchoedReturnKeyIsRedactedFromTheFailurePage(): void
    {
        $recordedPayload = null;
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedPayload): ResponseInterface {
                $recordedPayload = $options['json'];

                return new Response(422, [], '{"posted":{"return_key":"' . $options['json']['return_key'] . '"}}');
            }
        );

        $body = $this->createAuthorizeController($client, new ActionResponse(), 'html', ['consumer' => self::CONSUMER])
            ->authorizeAction('laravel-state');

        self::assertStringNotContainsString($recordedPayload['return_key'], $body);
        self::assertStringContainsString('[REDACTED_JWT]', $body);
    }

    /**
     * @param array<string, mixed>|null $recordedPayload
     * @return Client&MockObject
     */
    private function createCallbackClientMock(ResponseInterface $callbackResponse, &$recordedPayload)
    {
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$recordedPayload, $callbackResponse): ResponseInterface {
                $recordedPayload = $options['json'];

                return $callbackResponse;
            }
        );

        return $client;
    }

    /**
     * @param array<string, string> $requestArguments
     * @return AgentController&MockObject
     */
    private function createAuthorizeController(
        Client $client,
        ActionResponse $response,
        string $format = 'html',
        array $requestArguments = []
    ) {
        $controller = $this->getMockBuilder(AgentController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createCallbackClient'])
            ->getMock();
        $controller->method('createCallbackClient')->willReturn($client);

        $this->setProperty($controller, 'logger', $this->createMock(LoggerInterface::class));
        $this->setProperty($controller, 'request', $this->createRequestMock($format, $requestArguments));
        $this->setProperty($controller, 'response', $response);
        $this->setProperty($controller, 'agentTokenService', $this->createTokenServiceMock());
        $this->setProperty($controller, 'agentRefreshTokenService', $this->createRefreshTokenServiceMock());
        $this->setProperty($controller, 'agentSigningKeyPushService', $this->createSilentSigningKeyPushService());
        $this->setProperty($controller, 'externalApiDomain', self::CALLBACK_DOMAIN);
        $this->setProperty($controller, 'apiDomain', self::API_DOMAIN);
        $this->setProperty($controller, 'apiKey', 'the-api-key');

        return $controller;
    }

    /**
     * @param array<string, string> $arguments
     * @return ActionRequest&MockObject
     */
    private function createRequestMock(string $format, array $arguments)
    {
        $request = $this->getMockBuilder(ActionRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasArgument', 'getArgument', 'getFormat'])
            ->getMock();
        $request->method('getFormat')->willReturn($format);
        $request->method('hasArgument')->willReturnCallback(
            static function (string $name) use ($arguments): bool {
                return array_key_exists($name, $arguments);
            }
        );
        $request->method('getArgument')->willReturnCallback(
            static function (string $name) use ($arguments) {
                return $arguments[$name] ?? null;
            }
        );

        return $request;
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

        return $tokenService;
    }

    /**
     * @return AgentRefreshTokenService&MockObject
     */
    private function createRefreshTokenServiceMock()
    {
        $refreshTokenService = $this->getMockBuilder(AgentRefreshTokenService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateOpaqueRefreshToken', 'commitRefreshTokenForNewFamily', 'isStorageReady'])
            ->getMock();
        $refreshTokenService->method('generateOpaqueRefreshToken')->willReturn(str_repeat('ab', 32));
        $refreshTokenService->method('isStorageReady')->willReturn(true);

        return $refreshTokenService;
    }

    /**
     * @return AgentSigningKeyPushService&MockObject
     */
    private function createSilentSigningKeyPushService()
    {
        $pushService = $this->getMockBuilder(AgentSigningKeyPushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['pushIfNecessary', 'isKeyConfirmed'])
            ->getMock();
        $pushService->method('pushIfNecessary')->willReturn(null);
        $pushService->method('isKeyConfirmed')->willReturn(true);

        return $pushService;
    }

    /**
     * @param mixed $value
     */
    private function setProperty(AgentController $controller, string $propertyName, $value): void
    {
        $property = (new ReflectionClass(AgentController::class))->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}

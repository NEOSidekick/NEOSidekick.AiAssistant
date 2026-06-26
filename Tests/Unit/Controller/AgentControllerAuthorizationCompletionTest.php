<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use NEOSidekick\AiAssistant\Controller\AgentController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class AgentControllerAuthorizationCompletionTest extends TestCase
{
    /**
     * @test
     */
    public function deriveOriginReturnsOriginWithoutPath(): void
    {
        self::assertSame(
            'https://api.neosidekick.com:8443',
            $this->invokeAgentControllerMethod('deriveOrigin', ['https://api.neosidekick.com:8443/path?query=1'])
        );
    }

    /**
     * @test
     */
    public function deriveOriginReturnsNullForInvalidDomain(): void
    {
        self::assertNull($this->invokeAgentControllerMethod('deriveOrigin', ['']));
        self::assertNull($this->invokeAgentControllerMethod('deriveOrigin', ['api.neosidekick.com']));
        self::assertNull($this->invokeAgentControllerMethod('deriveOrigin', ['javascript://api.neosidekick.com']));
    }

    /**
     * @test
     */
    public function completionResponsePostsSuccessEventToTrustedOrigin(): void
    {
        $response = $this->invokeAgentControllerMethod('buildAuthorizationCompleteResponse', [
            'https://api.neosidekick.com',
        ]);

        self::assertStringContainsString('window.opener.postMessage', $response);
        self::assertStringContainsString('neosidekick-agent-authorization-complete', $response);
        self::assertStringContainsString('"https:\/\/api.neosidekick.com"', $response);
    }

    /**
     * @test
     */
    public function completionResponseDoesNotPostSuccessEventWithoutTrustedOrigin(): void
    {
        $response = $this->invokeAgentControllerMethod('buildAuthorizationCompleteResponse', [null]);

        self::assertStringNotContainsString('window.opener.postMessage', $response);
        self::assertStringNotContainsString('neosidekick-agent-authorization-complete', $response);
        self::assertStringContainsString('window.close();', $response);
    }

    /**
     * @param array<mixed> $arguments
     */
    private function invokeAgentControllerMethod(string $methodName, array $arguments): mixed
    {
        $controller = (new ReflectionClass(AgentController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AgentController::class, $methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($controller, $arguments);
    }
}

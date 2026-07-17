<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller\BackendModule;

use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Ensures the Configuration backend module derives the `domain` iframe parameter the
 * same way the chat iframes do (via NEOSidekickInternalHelper::domain()), URL-encodes it,
 * and omits it when no host can be determined.
 */
class ConfigurationControllerSiteDomainTest extends TestCase
{
    /**
     * @test
     */
    public function getSiteDomainReturnsUrlEncodedDomainWhenHostIsPresent(): void
    {
        $result = $this->invokeGetSiteDomain('https://www.example.com');

        self::assertSame(rawurlencode('https://www.example.com'), $result);
    }

    /**
     * @test
     */
    public function getSiteDomainReturnsEmptyStringWhenHostCannotBeDetermined(): void
    {
        $result = $this->invokeGetSiteDomain('http://');

        self::assertSame('', $result);
    }

    private function invokeGetSiteDomain(string $domain): string
    {
        $helper = $this->createMock(NEOSidekickInternalHelper::class);
        $helper->method('domain')->willReturn($domain);

        $controller = new ConfigurationController();
        $property = new ReflectionProperty(ConfigurationController::class, 'neosidekickInternalHelper');
        $property->setAccessible(true);
        $property->setValue($controller, $helper);

        $method = new ReflectionMethod(ConfigurationController::class, 'getSiteDomain');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }
}

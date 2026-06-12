<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use NEOSidekick\AiAssistant\Controller\AgentController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the silent re-authorization security fix: authorizeAction must enforce CSRF protection.
 *
 * The silent flow POSTs to do-authorize programmatically from the Neos UI plugin, carrying a valid
 * __csrfToken, so there is no reason to skip protection. Re-adding @Flow\SkipCsrfProtection here
 * would re-open the original finding, hence this regression guard.
 */
class AgentControllerCsrfProtectionTest extends TestCase
{
    /**
     * @test
     */
    public function authorizeActionDoesNotSkipCsrfProtection(): void
    {
        $docComment = (new ReflectionMethod(AgentController::class, 'authorizeAction'))->getDocComment();

        self::assertIsString($docComment);
        self::assertStringNotContainsString('@Flow\SkipCsrfProtection', $docComment);
    }
}

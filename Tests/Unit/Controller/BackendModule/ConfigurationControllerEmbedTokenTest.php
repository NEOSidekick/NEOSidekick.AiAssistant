<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller\BackendModule;

use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The embed token the settings iframe carries. Three properties are load-bearing: a minted
 * token reaches the view unaltered, an install without a usable signing keypair still
 * renders the module with the token absent, and minting one never creates a keypair - an
 * unpushed key cannot mint a token the backend accepts, and the module is reachable by
 * every editor, not by administrators only.
 */
class ConfigurationControllerEmbedTokenTest extends TestCase
{
    #[Test]
    public function theMintedEmbedTokenIsAssignedToTheView(): void
    {
        $tokenService = $this->createMock(AgentTokenService::class);
        $tokenService->method('generateEmbedToken')->willReturn('header.payload.signature');

        $assignments = $this->renderIndexAction($tokenService);

        self::assertSame('header.payload.signature', $assignments['embedToken']);
    }

    #[Test]
    public function aFailedMintAssignsNullAndLeavesTheOtherViewVariablesUntouched(): void
    {
        $tokenService = $this->createMock(AgentTokenService::class);
        $tokenService->method('generateEmbedToken')->willThrowException(new AgentTokenException('no keypair'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $assignments = $this->renderIndexAction($tokenService, $logger);

        self::assertNull($assignments['embedToken']);
        self::assertSame('test-api-key', $assignments['apiKey']);
        self::assertSame('https://api.neosidekick.com', $assignments['apiDomain']);
        self::assertSame(rawurlencode('https://neos.example.com'), $assignments['siteDomain']);
        self::assertFalse($assignments['connectionHealth']);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderIndexAction(AgentTokenService $tokenService, ?LoggerInterface $logger = null): array
    {
        $assignments = [];
        $view = $this->createMock(FusionView::class);
        $view->method('assign')->willReturnCallback(function (string $key, $value) use (&$assignments, $view) {
            $assignments[$key] = $value;

            return $view;
        });

        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => 'none', 'pushedAt' => '']);
        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('domain')->willReturn('https://neos.example.com');

        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('hasRole')->willReturn(false);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->method('uriFor')->willReturn('/regenerate');
        $controllerContext = $this->createMock(ControllerContext::class);
        $controllerContext->method('getFlashMessageContainer')->willReturn(new FlashMessageContainer());

        $controller = new ConfigurationController();
        $this->setProperty($controller, 'view', $view);
        $this->setProperty($controller, 'securityContext', $securityContext);
        $this->setProperty($controller, 'uriBuilder', $uriBuilder);
        $this->setProperty($controller, 'controllerContext', $controllerContext);
        $this->setProperty($controller, 'apiKey', 'test-api-key');
        $this->setProperty($controller, 'apiDomain', 'https://api.neosidekick.com');
        $this->setProperty($controller, 'neosidekickInternalHelper', $internalHelper);
        $this->setProperty($controller, 'agentSigningKeyPushService', $pushService);
        $this->setProperty($controller, 'agentTokenService', $tokenService);
        $this->setProperty($controller, 'logger', $logger ?? $this->createMock(LoggerInterface::class));

        $controller->indexAction();

        return $assignments;
    }

    /**
     * @param mixed $value
     */
    private function setProperty(ConfigurationController $controller, string $name, $value): void
    {
        $property = new ReflectionProperty(ConfigurationController::class, $name);
        $property->setAccessible(true);
        $property->setValue($controller, $value);
    }
}

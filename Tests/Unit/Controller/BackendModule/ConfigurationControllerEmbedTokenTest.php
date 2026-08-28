<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller\BackendModule;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
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
    /** @test */
    public function theMintedEmbedTokenIsAssignedToTheView(): void
    {
        $tokenService = $this->createMock(AgentTokenService::class);
        $tokenService->method('generateEmbedToken')->willReturn('header.payload.signature');

        $assignments = $this->renderIndexAction($tokenService);

        self::assertSame('header.payload.signature', $assignments['embedToken']);
    }

    /** @test */
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
        self::assertSame(
            ['exists' => false, 'fingerprint' => '', 'status' => 'none', 'pushedAt' => '', 'regenerateIncomplete' => false, 'relabelPending' => false, 'registeredDomain' => null, 'currentDomain' => null, 'domainConflict' => false],
            $assignments['signingKey']
        );
    }

    /**
     * @test
     */
    public function mintingTheEmbedTokenOnAKeylessInstallationNeverCreatesTheSigningKeyRow(): void
    {
        $repository = new InMemoryAgentSigningKeyRecordRepository();
        $tokenService = $this->createMock(AgentTokenService::class);
        $tokenService->method('generateEmbedToken')->willThrowException(new AgentTokenException('no keypair'));

        $assignments = $this->renderIndexAction($tokenService, null, $this->createKeyPairService($repository));

        self::assertNull($repository->findInstallRecord(), 'the embed token minting generated a keypair');
        self::assertNull($assignments['embedToken']);
    }

    /**
     * A real keypair service on an in-memory row, so "did the render generate a key?" is a
     * question about the row rather than about a mock's expectations.
     */
    private function createKeyPairService(InMemoryAgentSigningKeyRecordRepository $repository): AgentKeyPairService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);

        $keyPairService = new AgentKeyPairService();
        $this->setPropertyOn($keyPairService, 'agentSigningKeyRecordRepository', $repository);
        $this->setPropertyOn($keyPairService, 'entityManager', $entityManager);
        $this->setPropertyOn($keyPairService, 'logger', $this->createMock(LoggerInterface::class));

        return $keyPairService;
    }

    private function setPropertyOn(object $object, string $name, mixed $value): void
    {
        $property = new ReflectionProperty(get_class($object), $name);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function renderIndexAction(AgentTokenService $tokenService, ?LoggerInterface $logger = null, ?AgentKeyPairService $keyPairService = null): array
    {
        $assignments = [];
        $view = $this->createMock(FusionView::class);
        $view->method('assign')->willReturnCallback(function (string $key, $value) use (&$assignments, $view) {
            $assignments[$key] = $value;

            return $view;
        });

        if ($keyPairService === null) {
            $keyPairService = $this->createMock(AgentKeyPairService::class);
            $keyPairService->method('hasKeyPair')->willReturn(false);
        }
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null]);
        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('domain')->willReturn('https://neos.example.com');

        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('hasRole')->willReturn(false);
        $securityContext->method('getCsrfProtectionToken')->willReturn('csrf-token');
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
        $this->setProperty($controller, 'agentKeyPairService', $keyPairService);
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

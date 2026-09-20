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
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * The Configuration module says one line about the connection to NEOSidekick only when it needs
 * attention, and links to the key management module for everything that can be done about it. Load-bearing: the render never
 * generates a keypair, an unreadable key row is a state rather than an error page, and the push
 * keeps its 60 s throttle - so a skipped push renders the line from the record.
 */
class ConfigurationControllerConnectionHealthTest extends TestCase
{
    /** @test */
    public function anAcceptedHostSetIsHealthy(): void
    {
        $assigned = $this->renderIndex(
            true,
            $this->healthyKeyPairService(),
            $this->pushService('confirmed', AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid', 'https://www.example.com', ['https://www.example.com'], 'accepted'))
        );

        self::assertTrue($assigned['connectionHealth']);
    }

    /**
     * The module has no vocabulary for the difference; the key management module does.
     *
     * @test
     */
    public function anyOtherVerdictNeedsAttention(): void
    {
        foreach (['base_host_unknown', 'expired', 'invalid_signature', 'empty'] as $hostsResult) {
            $assigned = $this->renderIndex(
                true,
                $this->healthyKeyPairService(),
                $this->pushService('confirmed', AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid', 'https://www.example.com', ['https://www.example.com'], $hostsResult))
            );

            self::assertFalse($assigned['connectionHealth'], $hostsResult);
        }
    }

    /** @test */
    public function aSkippedPushFallsBackToTheRecordedRegistration(): void
    {
        $assigned = $this->renderIndex(true, $this->healthyKeyPairService(), $this->pushService('confirmed', null));
        self::assertTrue($assigned['connectionHealth']);

        $reenrolled = $this->renderIndex(true, $this->healthyKeyPairService(), $this->pushService('reenrolled', null));
        self::assertTrue($reenrolled['connectionHealth'], 'a re-enrolled key is a registered key');

        foreach (['none', 'pending', 'revoked'] as $recordedStatus) {
            $unconfirmed = $this->renderIndex(true, $this->healthyKeyPairService(), $this->pushService($recordedStatus, null));
            self::assertFalse($unconfirmed['connectionHealth'], $recordedStatus);
        }
    }

    /**
     * The record is older than the answer just received.
     *
     * @test
     */
    public function aPushThatRanAndWasNotAcceptedNeedsAttentionWhateverTheRecordSays(): void
    {
        foreach ([
            'the push failed' => AgentSigningKeyPushResult::failure('Connection timed out'),
            'no verdict echoed' => AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid', 'https://www.example.com'),
            'the host set was refused' => AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid', 'https://www.example.com', ['https://www.example.com'], 'base_host_unknown'),
        ] as $case => $pushResult) {
            $assigned = $this->renderIndex(true, $this->healthyKeyPairService(), $this->pushService('confirmed', $pushResult));

            self::assertFalse($assigned['connectionHealth'], $case);
        }
    }

    /**
     * The module is open to every editor and the push transmits this installation's identity. A
     * keyless installation pushes nothing either: the unattended push would mint a key.
     *
     * @test
     */
    public function onlyAnAdministratorWithAKeyPushesOnTheRender(): void
    {
        $pushService = $this->pushService('confirmed', AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid', 'https://www.example.com', ['https://www.example.com'], 'accepted'));
        $pushService->expects(self::once())->method('pushIfNecessary')->with(true);
        $this->renderIndex(true, $this->healthyKeyPairService(), $pushService);

        $editorPushService = $this->pushService('confirmed', null);
        $editorPushService->expects(self::never())->method('pushIfNecessary');
        $assigned = $this->renderIndex(false, $this->healthyKeyPairService(), $editorPushService);
        self::assertTrue($assigned['connectionHealth'], 'the editor still sees the recorded state');

        $keyless = $this->createMock(AgentKeyPairService::class);
        $keyless->method('hasKeyPair')->willReturn(false);
        $keyless->expects(self::never())->method('generateKeyPair');
        $keylessPushService = $this->pushService('none', null);
        $keylessPushService->expects(self::never())->method('pushIfNecessary');
        $assigned = $this->renderIndex(true, $keyless, $keylessPushService);
        self::assertFalse($assigned['connectionHealth']);
    }

    /** @test */
    public function aForcedPushThatThrowsIsLoggedAndLeavesTheRecordedStateStanding(): void
    {
        $pushService = $this->pushService('confirmed', null);
        $pushService->method('pushIfNecessary')->willThrowException(new RuntimeException('unexpected'));

        $assigned = $this->renderIndex(true, $this->healthyKeyPairService(), $pushService);

        self::assertTrue($assigned['connectionHealth']);
    }

    /** @test */
    public function anUnreadableSigningKeyRowLeavesTheRecordedStatusStanding(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willThrowException(new RuntimeException('the row could not be loaded'));

        $assigned = $this->renderIndex(true, $keyPairService, $this->pushService('confirmed', null));

        self::assertTrue($assigned['connectionHealth'], 'the recorded status still stands');
    }

    /** @test */
    public function theModuleAssignsTheKeyManagementLinkAndNothingAboutKeys(): void
    {
        $assigned = $this->renderIndex(true);

        self::assertSame('/neos/ai-assistant/keyManagement', $assigned['keyManagementUri']);
        self::assertSame('csrf-token', $assigned['csrfToken']);
        self::assertTrue($assigned['isAdministrator']);
        foreach (['signingKey', 'regenerateActionUri', 'regenerateFailure', 'regenerateRejectionReason'] as $goneVariable) {
            self::assertArrayNotHasKey($goneVariable, $assigned);
        }
        self::assertFalse(method_exists(ConfigurationController::class, 'regenerateSigningKeyAction'));
    }

    /**
     * The module is reachable by every editor, so rendering it must never mint an identity.
     *
     * @test
     */
    public function indexActionOnAKeylessInstallationNeverCreatesTheSigningKeyRow(): void
    {
        $repository = new InMemoryAgentSigningKeyRecordRepository();

        $assigned = $this->renderIndex(true, $this->createKeyPairService($repository));

        self::assertNull($repository->findInstallRecord(), 'the render generated a keypair');
        self::assertFalse($assigned['connectionHealth']);
    }

    /**
     * @return AgentSigningKeyPushService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function pushService(string $recordedStatus, ?AgentSigningKeyPushResult $pushResult): AgentSigningKeyPushService
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => $recordedStatus, 'pushedAt' => '']);
        $pushService->method('pushIfNecessary')->willReturn($pushResult);

        return $pushService;
    }

    /**
     * @return AgentKeyPairService&\PHPUnit\Framework\MockObject\MockObject
     */
    private function healthyKeyPairService(): AgentKeyPairService
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('getFingerprint')->willReturn('AA:BB');

        return $keyPairService;
    }

    /**
     * @return array<string, mixed> The variables indexAction assigned to the view
     */
    private function renderIndex(bool $isAdministrator, ?AgentKeyPairService $keyPairService = null, ?AgentSigningKeyPushService $pushService = null): array
    {
        if ($keyPairService === null) {
            $keyPairService = $this->createMock(AgentKeyPairService::class);
            $keyPairService->method('hasKeyPair')->willReturn(false);
        }
        $controller = $this->createController($pushService ?? $this->pushService('none', null), $keyPairService, $isAdministrator);

        $assigned = [];
        $view = $this->createMock(FusionView::class);
        $view->method('assign')->willReturnCallback(function (string $key, mixed $value) use (&$assigned, $view): FusionView {
            $assigned[$key] = $value;

            return $view;
        });
        $this->setProtectedProperty($controller, 'view', $view);

        $controller->indexAction();

        return $assigned;
    }

    private function createController(
        AgentSigningKeyPushService $pushService,
        AgentKeyPairService $keyPairService,
        bool $isAdministrator = true
    ): ConfigurationController {
        $controller = new class () extends ConfigurationController {
            protected function neosBackendModuleUri(string $modulePath): string
            {
                return '/neos/' . $modulePath;
            }
        };

        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('hasRole')->willReturnCallback(fn (string $roleIdentifier): bool => $roleIdentifier === 'Neos.Neos:Administrator' && $isAdministrator);
        $securityContext->method('getCsrfProtectionToken')->willReturn('csrf-token');

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();

        $controllerContext = $this->createMock(ControllerContext::class);
        $controllerContext->method('getFlashMessageContainer')->willReturn(new FlashMessageContainer());

        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('domain')->willReturn('https://neos.example.com');

        $agentTokenService = $this->createMock(AgentTokenService::class);
        $agentTokenService->method('generateEmbedToken')->willReturn('embed-token');

        $this->setProtectedProperty($controller, 'agentKeyPairService', $keyPairService);
        $this->setProtectedProperty($controller, 'agentSigningKeyPushService', $pushService);
        $this->setProtectedProperty($controller, 'securityContext', $securityContext);
        $this->setProtectedProperty($controller, 'uriBuilder', $uriBuilder);
        $this->setProtectedProperty($controller, 'controllerContext', $controllerContext);
        $this->setProtectedProperty($controller, 'neosidekickInternalHelper', $internalHelper);
        $this->setProtectedProperty($controller, 'agentTokenService', $agentTokenService);
        $this->setProtectedProperty($controller, 'logger', $this->createMock(LoggerInterface::class));
        $this->setProtectedProperty($controller, 'apiKey', 'test-api-key');
        $this->setProtectedProperty($controller, 'apiDomain', 'https://api.neosidekick.test');

        return $controller;
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
        $this->setProtectedProperty($keyPairService, 'agentSigningKeyRecordRepository', $repository);
        $this->setProtectedProperty($keyPairService, 'entityManager', $entityManager);
        $this->setProtectedProperty($keyPairService, 'logger', $this->createMock(LoggerInterface::class));

        return $keyPairService;
    }

    private function setProtectedProperty(object $object, string $propertyName, mixed $value): void
    {
        $className = $object instanceof ConfigurationController ? ConfigurationController::class : get_class($object);
        $property = new ReflectionProperty($this->declaringClass($className, $propertyName), $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Properties like `view`, `uriBuilder` and `controllerContext` are declared on Flow's base
     * controllers, not on ConfigurationController.
     */
    private function declaringClass(string $className, string $propertyName): string
    {
        $class = new \ReflectionClass($className);
        while ($class !== false && !$class->hasProperty($propertyName)) {
            $class = $class->getParentClass();
        }
        if ($class === false) {
            throw new RuntimeException('No property ' . $propertyName . ' on ' . $className);
        }

        return $class->getProperty($propertyName)->getDeclaringClass()->getName();
    }
}

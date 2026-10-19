<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller\BackendModule;

use Neos\Error\Messages\Error;
use Neos\Error\Messages\Message;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Controller\BackendModule\KeyManagementController;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * The key management module answers one question - what is wrong with this installation's
 * connection - with one state, and offers exactly one action per answer. Load-bearing: the
 * remedy precedence, the push past the throttle and the refusal of the move without a
 * registered domain or while a key change is pending.
 */
class KeyManagementControllerTest extends TestCase
{
    public const REDIRECT_STOPS_ACTION = 1760900001;

    #[Test]
    public function eachHostVerdictBecomesItsOwnState(): void
    {
        foreach ([
            'accepted' => ['healthy', null],
            'base_host_unknown' => ['host_unknown', null],
            'expired' => ['check_failed', 'expired'],
            'invalid_signature' => ['check_failed', 'generic'],
            'empty' => ['check_failed', 'generic'],
            'a_reason_coined_later' => ['check_failed', 'generic'],
        ] as $hostsResult => [$expectedState, $expectedVariant]) {
            $keyState = $this->deriveKeyState($this->healthyKeyPairService(), $this->answer(['https://www.example.com'], $hostsResult));

            self::assertSame([$expectedState, $expectedVariant], [$keyState['state'], $keyState['variant']], $hostsResult);
            self::assertSame($hostsResult, $keyState['rawResult']);
        }
    }

    #[Test]
    public function anAnswerWithoutAVerdictOrWithoutAHostSetIsACheckThatDidNotComplete(): void
    {
        $noVerdict = $this->deriveKeyState($this->healthyKeyPairService(), $this->answer(['https://www.example.com'], null));
        self::assertSame(['check_failed', 'generic'], [$noVerdict['state'], $noVerdict['variant']]);
        self::assertNull($noVerdict['rawResult']);

        $noHosts = $this->deriveKeyState($this->healthyKeyPairService(), $this->answer(null, null));
        self::assertSame(['check_failed', 'generic'], [$noHosts['state'], $noHosts['variant']]);
        self::assertSame([], $noHosts['registeredHosts']);
    }

    #[Test]
    public function aFailedOrSkippedPushIsACheckThatDidNotComplete(): void
    {
        $failed = $this->deriveKeyState(
            $this->healthyKeyPairService(),
            AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: kid_mismatch', 'kid', 'kid_mismatch')
        );
        self::assertSame(['check_failed', 'generic'], [$failed['state'], $failed['variant']]);
        self::assertSame('kid_mismatch', $failed['rawResult'], 'the reason is kept for the technical details');

        $withoutAReason = $this->deriveKeyState($this->healthyKeyPairService(), AgentSigningKeyPushResult::failure('Connection timed out'));
        self::assertSame('Connection timed out', $withoutAReason['rawResult']);

        $skipped = $this->deriveKeyState($this->healthyKeyPairService(), null);
        self::assertSame(['check_failed', 'generic'], [$skipped['state'], $skipped['variant']]);
        self::assertNull($skipped['rawResult']);
    }

    /**
     * The banner would otherwise tell the administrator to wait for a rotation that can never
     * be confirmed.
     */
    #[Test]
    public function aRevokedKeyOutranksTheHostVerdictAndThePendingBanner(): void
    {
        $keyPairService = $this->healthyKeyPairService();
        $keyState = $this->deriveKeyState($keyPairService, $this->answer(['https://www.example.com'], 'accepted'), 'revoked');

        self::assertSame(['needs_new_key', 'revoked'], [$keyState['state'], $keyState['variant']]);

        $pending = $this->healthyKeyPairService();
        $pending->method('hasPendingKeyPair')->willReturn(true);
        $whilePending = $this->deriveKeyState($pending, $this->answer(['https://www.example.com'], 'accepted'), 'revoked');

        self::assertSame(['needs_new_key', 'revoked'], [$whilePending['state'], $whilePending['variant']]);
        self::assertFalse($whilePending['pendingChange'], 'the banner would ask the administrator to wait for nothing');
    }

    #[Test]
    public function aMissingUnusableOrUnreadableKeyNeedsANewOne(): void
    {
        $missing = $this->createMock(AgentKeyPairService::class);
        $missing->method('hasKeyPair')->willReturn(false);
        $missing->expects(self::never())->method('generateKeyPair');
        $missingState = $this->deriveKeyState($missing, null);
        self::assertSame(['needs_new_key', 'missing'], [$missingState['state'], $missingState['variant']]);
        self::assertFalse($missingState['exists']);

        $unusable = $this->createMock(AgentKeyPairService::class);
        $unusable->method('hasKeyPair')->willReturn(true);
        $unusable->method('isLiveKeyPairUsable')->willReturn(false);
        $unusableState = $this->deriveKeyState($unusable, $this->answer(['https://www.example.com'], 'accepted'));
        self::assertSame(['needs_new_key', 'missing'], [$unusableState['state'], $unusableState['variant']]);
        self::assertTrue($unusableState['exists'], 'the row is there and restorable');

        $unreadable = $this->createMock(AgentKeyPairService::class);
        $unreadable->method('hasPendingKeyPair')->willThrowException(
            new AgentSigningKeyStorageException('The agent signing keypair could not be loaded from the database.', 1757000012)
        );
        $unreadableState = $this->deriveKeyState($unreadable, null);
        self::assertSame(['needs_new_key', 'missing'], [$unreadableState['state'], $unreadableState['variant']]);
    }

    #[Test]
    public function aPendingPairIsReportedAlongsideTheHostVerdict(): void
    {
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->method('hasPendingKeyPair')->willReturn(true);

        $keyState = $this->deriveKeyState($keyPairService, $this->answer(['https://www.example.com'], 'base_host_unknown'));

        self::assertSame('host_unknown', $keyState['state']);
        self::assertTrue($keyState['pendingChange']);
    }

    /**
     * The label, its host alone (the address the page names as registered), the stored set
     * trimmed, and the host this page was opened on.
     */
    #[Test]
    public function theAddressesAreReportedAsTheyWereEchoed(): void
    {
        $keyState = $this->deriveKeyState(
            $this->healthyKeyPairService(),
            $this->answer(['https://www.example.com', ' http://academy.example:8080 ', ''], 'base_host_unknown'),
            'confirmed',
            'Staging.Example.COM'
        );

        self::assertSame('https://www.example.com', $keyState['registeredDomain']);
        self::assertSame('www.example.com', $keyState['registeredHost']);
        self::assertSame(['https://www.example.com', 'http://academy.example:8080'], $keyState['registeredHosts']);
        self::assertSame('staging.example.com', $keyState['thisHost']);
        self::assertSame('AA:BB', $keyState['fingerprint']);
        self::assertSame('root-kid-1', $keyState['installRootKid']);
    }

    /**
     * The page exists to produce a fresh verdict, so its push goes past the throttle. That it
     * never mints a key is the push service's promise ({@see AgentSigningKeyPushService::pushExistingKey}).
     */
    #[Test]
    public function theIndexPushesPastTheThrottle(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '']);
        $pushService->expects(self::once())->method('pushExistingKey')->with(true)
            ->willReturn($this->answer(['https://www.example.com'], 'accepted'));

        $assigned = $this->renderIndex($this->healthyKeyPairService(), $pushService);

        self::assertSame('healthy', $assigned['keyState']['state']);
    }

    /**
     * The verdict is about right now, and the answers send the administrator away and back, so
     * the page must not be restored from the browser's back / forward cache.
     */
    #[Test]
    public function theIndexForbidsTheBrowsersBackForwardCache(): void
    {
        $response = new ActionResponse();

        $this->renderIndex($this->healthyKeyPairService(), response: $response);

        self::assertStringContainsString('no-store', (string)$response->getHttpHeader('Cache-Control'));
        self::assertSame('no-cache', $response->getHttpHeader('Pragma'));
    }

    #[Test]
    public function theIndexAssignsTheFormTargetsAndTheTokenTheFormsCarry(): void
    {
        $assigned = $this->renderIndex();

        self::assertSame('/neos/ai-assistant/keyManagement?replaceKey', $assigned['replaceKeyActionUri']);
        self::assertSame('/neos/ai-assistant/keyManagement?reenrol', $assigned['reenrolActionUri']);
        self::assertSame('/neos/ai-assistant/keyManagement?relabel', $assigned['relabelActionUri']);
        self::assertSame('csrf-token', $assigned['csrfToken']);
        self::assertNull($assigned['regenerateFailure']);
        self::assertNull($assigned['regenerateRejectionReason']);
        self::assertNull($assigned['relabelError']);
    }

    /**
     * Read raw, so a '%' in the backend's answer cannot break the render, and consumed, so a
     * reload does not show it again. A refused move travels on its own code.
     */
    #[Test]
    public function theIndexConsumesOnlyItsOwnFlashMessages(): void
    {
        $container = new FlashMessageContainer();
        $unrelated = new Message('unrelated');
        $container->addMessage($unrelated);
        $container->addMessage(new Error('rejected with 100% certainty', KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE, [], 'chain_domain_mismatch'));
        $container->addMessage(new Error('A key change is still waiting for NEOSidekick.', KeyManagementController::RELABEL_REFUSED_MESSAGE_CODE));

        $assigned = $this->renderIndex(null, null, $container);

        self::assertSame('rejected with 100% certainty', $assigned['regenerateFailure']);
        self::assertSame('chain_domain_mismatch', $assigned['regenerateRejectionReason']);
        self::assertSame('A key change is still waiting for NEOSidekick.', $assigned['relabelError']);
        self::assertSame([$unrelated], $container->getMessagesAndFlush(), 'the unrelated message is kept for whoever renders it');
    }

    #[Test]
    public function aFailureWithoutAReasonAssignsNone(): void
    {
        $container = new FlashMessageContainer();
        $container->addMessage(new Error('Connection timed out', KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE));

        $assigned = $this->renderIndex(null, null, $container);

        self::assertSame('Connection timed out', $assigned['regenerateFailure']);
        self::assertNull($assigned['regenerateRejectionReason']);
    }

    /**
     * Its code must differ from the failure code, which the index consumes into the red box.
     */
    #[Test]
    public function replaceKeyRotatesTheKeyChainedAndConfirmsTheSuccess(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, false, false)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-1'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '']);
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->expects(self::never())->method('generateKeyPair');
        $controller = $this->createController($pushService, $keyPairService);

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['Der Signaturschlüssel wurde erneuert.', '', Message::SEVERITY_OK, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
        self::assertNotSame(KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE);
    }

    /**
     * The Neos chrome renders flash messages untranslated, so an untranslatable body must not
     * reach the administrator as a translation id.
     */
    #[Test]
    public function theSuccessMessageFallsBackToEnglishWhenTheUnitIsMissing(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService, $this->healthyKeyPairService(), translation: null);

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The signing key was regenerated. This installation now identifies itself with the new key.', '', Message::SEVERITY_OK, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    #[Test]
    public function anUnconfirmedOutcomeWarnsInsteadOfConfirming(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('revoked', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService, $this->healthyKeyPairService(), translation: null);

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, , $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('reports it as revoked', $body);
        self::assertSame([Message::SEVERITY_WARNING, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE], [$severity, $code]);
    }

    #[Test]
    public function aFailedKeyChangeIsCarriedToTheNextRenderAsAFlashMessage(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: chain_domain_mismatch', 'new-kid', 'chain_domain_mismatch'));
        $controller = $this->createController($pushService, $this->healthyKeyPairService());

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The NEOSidekick backend rejected the signing key with status 422: chain_domain_mismatch', 'chain_domain_mismatch', Message::SEVERITY_ERROR, KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * It would have to announce the successor unchained, which enrols this installation anew -
     * what the copy answer asks for explicitly.
     */
    #[Test]
    public function replaceKeyOnAnUnusableStoredKeyAbortsInsteadOfReenrollingSilently(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::never())->method('rotateKeyPair');
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(false);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, $title, $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('the stored key is unusable', $body);
        self::assertStringContainsString('backup', $body);
        self::assertSame(['', Message::SEVERITY_ERROR, KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE], [$title, $severity, $code]);
    }

    /**
     * Reported as what it is, because every tool connected here has to be connected again.
     */
    #[Test]
    public function reenrolAnnouncesTheKeyUnchainedAndReportsTheNewInstallation(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, false, true)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-2'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'reenrolled', 'pushedAt' => '']);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(false);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'reenrol');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['Registered as a new installation. Connected tools had to be reconnected with the new address.', '', Message::SEVERITY_OK, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * What the push recorded wins over what was asked for.
     */
    #[Test]
    public function aReenrolmentOnAKeyThatBecameUsableAgainReportsAChainedRotation(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, false, true)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-1'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '']);
        $controller = $this->createController($pushService, $this->healthyKeyPairService(), translation: null);

        $this->runAction($controller, 'reenrol');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body] = $controller->flashMessages[0];
        self::assertStringContainsString('The signing key was regenerated.', $body);
    }

    /**
     * It reaches the rotation as the relabel flag, not as a re-enrolment. The POST itself is the
     * confirmation: the browser dialog on the form is what names the consequence.
     */
    #[Test]
    public function theMoveIsCommittedAsARelabelRotation(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('pushExistingKey')->willReturn($this->answer(['https://www.example.com'], 'base_host_unknown'));
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, true, false)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-1'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '']);
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->expects(self::never())->method('clearPendingRelabel');
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'relabel');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['This installation is now registered under its current domain. Connected tools keep working.', '', Message::SEVERITY_OK, KeyManagementController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
    }

    #[Test]
    public function aMoveWithoutARegisteredDomainIsRefused(): void
    {
        foreach ([
            'no label echoed' => $this->answer(['https://www.example.com'], 'base_host_unknown', null),
            'the push failed' => AgentSigningKeyPushResult::failure('Connection timed out'),
            'the push was skipped' => null,
        ] as $case => $pushAnswer) {
            $pushService = $this->createMock(AgentSigningKeyPushService::class);
            $pushService->method('pushExistingKey')->willReturn($pushAnswer);
            $pushService->expects(self::never())->method('rotateKeyPair');
            $controller = $this->createController($pushService, $this->healthyKeyPairService(), translation: null);

            $this->runAction($controller, 'relabel');

            /** @phpstan-ignore-next-line the test double records the flash messages */
            [$body, , , $code] = $controller->flashMessages[0];
            self::assertStringContainsString('did not report a registered domain', $body, $case);
            self::assertSame(KeyManagementController::RELABEL_REFUSED_MESSAGE_CODE, $code, $case);
        }
    }

    /**
     * The push sends the pending pair, not the live key, so a stale POST would commit that
     * waiting successor as a side effect.
     */
    #[Test]
    public function aMoveWhileAKeyChangeIsPendingIsRefusedBeforeAnyPush(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::never())->method('pushExistingKey');
        $pushService->expects(self::never())->method('rotateKeyPair');
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->method('hasPendingKeyPair')->willReturn(true);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'relabel');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, , , $code] = $controller->flashMessages[0];
        self::assertStringContainsString('still waiting for NEOSidekick', $body);
        self::assertSame(KeyManagementController::RELABEL_REFUSED_MESSAGE_CODE, $code);
    }

    /**
     * The waiting successor may carry a relabel intent, so a stale POST would commit a move that
     * was never confirmed here. The copy answer is not refused: it mints a fresh pair on purpose
     * and is the way out of a lineage that cannot be continued.
     */
    #[Test]
    public function replacingTheKeyWhileAKeyChangeIsPendingIsRefusedBeforeAnyPush(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::never())->method('rotateKeyPair');
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->method('hasPendingKeyPair')->willReturn(true);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'replaceKey');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, , , $code] = $controller->flashMessages[0];
        self::assertStringContainsString('still waiting for NEOSidekick', $body);
        self::assertSame(KeyManagementController::RELABEL_REFUSED_MESSAGE_CODE, $code);
        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);

        $reenrolPushService = $this->createMock(AgentSigningKeyPushService::class);
        $reenrolPushService->expects(self::once())->method('rotateKeyPair')->with(null, false, true)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-2'));
        $reenrolPushService->method('getPushStatus')->willReturn(['status' => 'reenrolled', 'pushedAt' => '']);
        $pendingKeyPairService = $this->healthyKeyPairService();
        $pendingKeyPairService->method('hasPendingKeyPair')->willReturn(true);
        $this->runAction($this->createController($reenrolPushService, $pendingKeyPairService, translation: null), 'reenrol');
    }

    /**
     * The flag sticks to the pending pair and the unattended editor-authorize push would transmit
     * it chained. A plain replacement must not touch a flag the CLI may have armed.
     */
    #[Test]
    public function aFailedMoveClearsThePendingRelabelFlagAndAPlainReplacementDoesNot(): void
    {
        $rejection = AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: kid_mismatch', 'new-kid', 'kid_mismatch');

        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('pushExistingKey')->willReturn($this->answer(['https://www.example.com'], 'base_host_unknown'));
        $pushService->method('rotateKeyPair')->willReturn($rejection);
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->expects(self::once())->method('clearPendingRelabel');
        $this->runAction($this->createController($pushService, $keyPairService, translation: null), 'relabel');

        $plainPushService = $this->createMock(AgentSigningKeyPushService::class);
        $plainPushService->method('rotateKeyPair')->willReturn($rejection);
        $plainKeyPairService = $this->healthyKeyPairService();
        $plainKeyPairService->expects(self::never())->method('clearPendingRelabel');
        $this->runAction($this->createController($plainPushService, $plainKeyPairService, translation: null), 'replaceKey');
    }

    /**
     * Disarming is best effort: the row may be unwritable for the very reason the push failed.
     */
    #[Test]
    public function aFailedMoveWhoseDisarmingThrowsStillReportsTheFailureAndRedirects(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('pushExistingKey')->willReturn($this->answer(['https://www.example.com'], 'base_host_unknown'));
        $pushService->method('rotateKeyPair')->willReturn(
            AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: kid_mismatch', 'new-kid', 'kid_mismatch')
        );
        $keyPairService = $this->healthyKeyPairService();
        $keyPairService->method('clearPendingRelabel')->willThrowException(new RuntimeException('the signing-key row could not be written'));
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $this->runAction($controller, 'relabel');

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, $title, $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('kid_mismatch', $body);
        self::assertSame(['kid_mismatch', Message::SEVERITY_ERROR, KeyManagementController::REGENERATION_FAILED_MESSAGE_CODE], [$title, $severity, $code]);
        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
    }

    /**
     * @param array<int, string>|null $hosts
     */
    private function answer(?array $hosts, ?string $hostsResult, ?string $registeredDomain = 'https://www.example.com'): AgentSigningKeyPushResult
    {
        return AgentSigningKeyPushResult::success('confirmed', 'kid', 'AA:BB', 'root-kid-1', $registeredDomain, $hosts, $hostsResult);
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
        $keyPairService->method('getRecord')->willReturn($this->recordWithPushState());

        return $keyPairService;
    }

    private function recordWithPushState(): AgentSigningKeyRecord
    {
        $record = new AgentSigningKeyRecord('private', 'public');
        foreach (['pushInstallRootKid' => 'root-kid-1', 'pushRegisteredDomain' => 'https://recorded.example.com'] as $propertyName => $value) {
            $property = new ReflectionProperty(AgentSigningKeyRecord::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($record, $value);
        }

        return $record;
    }

    /**
     * @return array{state: string, variant: string|null, pendingChange: bool, registeredDomain: string|null, registeredHost: string|null, thisHost: string|null, registeredHosts: array<int, string>, fingerprint: string, installRootKid: string|null, rawResult: string|null, exists: bool}
     */
    private function deriveKeyState(AgentKeyPairService $keyPairService, ?AgentSigningKeyPushResult $pushResult, string $recordedStatus = 'confirmed', ?string $requestHost = null): array
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => $recordedStatus, 'pushedAt' => '']);
        $pushService->method('pushExistingKey')->willReturn($pushResult);

        return $this->renderIndex($keyPairService, $pushService, requestHost: $requestHost)['keyState'];
    }

    /**
     * @return array<string, mixed> The variables indexAction assigned to the view
     */
    private function renderIndex(
        ?AgentKeyPairService $keyPairService = null,
        ?AgentSigningKeyPushService $pushService = null,
        ?FlashMessageContainer $flashMessageContainer = null,
        ?string $requestHost = null,
        ?ActionResponse $response = null
    ): array {
        if ($keyPairService === null) {
            $keyPairService = $this->createMock(AgentKeyPairService::class);
            $keyPairService->method('hasKeyPair')->willReturn(false);
        }
        if ($pushService === null) {
            $pushService = $this->createMock(AgentSigningKeyPushService::class);
            $pushService->method('getPushStatus')->willReturn(['status' => 'none', 'pushedAt' => '']);
        }
        $controller = $this->createController($pushService, $keyPairService, $flashMessageContainer, requestHost: $requestHost, response: $response);

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

    /**
     * Runs the action up to its redirect, which the test double turns into a stop like Flow's own.
     */
    private function runAction(KeyManagementController $controller, string $action): void
    {
        try {
            $controller->{$action . 'Action'}();
        } catch (RuntimeException $exception) {
            if ($exception->getCode() !== self::REDIRECT_STOPS_ACTION) {
                throw $exception;
            }
        }
    }

    private function createController(
        AgentSigningKeyPushService $pushService,
        AgentKeyPairService $keyPairService,
        ?FlashMessageContainer $flashMessageContainer = null,
        ?string $translation = 'Der Signaturschlüssel wurde erneuert.',
        ?string $requestHost = null,
        ?ActionResponse $response = null
    ): KeyManagementController {
        $controller = new class () extends KeyManagementController {
            public ?string $requestHost = null;

            /**
             * @var array<int, string>
             */
            public array $redirects = [];

            /**
             * @var array<int, array{0: string, 1: string, 2: string, 3: int|null}>
             */
            public array $flashMessages = [];

            protected function redirect($actionName, $controllerName = null, $packageKey = null, array $arguments = [], $delay = 0, $statusCode = 303, $format = null): never
            {
                $this->redirects[] = $actionName;
                // Flow 9 declares redirect() as never returning, so the double stops the action like Flow does.
                throw new RuntimeException('redirected to ' . $actionName, KeyManagementControllerTest::REDIRECT_STOPS_ACTION);
            }

            public function addFlashMessage($messageBody, $messageTitle = '', $severity = Message::SEVERITY_OK, array $messageArguments = [], $messageCode = null)
            {
                $this->flashMessages[] = [$messageBody, $messageTitle, $severity, $messageCode];
            }

            protected function currentRequestHost(): ?string
            {
                return $this->requestHost;
            }
        };
        $controller->requestHost = $requestHost;

        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('getCsrfProtectionToken')->willReturn('csrf-token');

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->method('uriFor')->willReturnCallback(
            static fn (string $actionName): string => '/neos/ai-assistant/keyManagement?' . $actionName
        );

        $controllerContext = $this->createMock(ControllerContext::class);
        $controllerContext->method('getFlashMessageContainer')->willReturn($flashMessageContainer ?? new FlashMessageContainer());

        $userService = $this->createMock(UserService::class);
        $userService->method('getInterfaceLanguage')->willReturn('de');

        $translator = $this->createMock(Translator::class);
        $translator->method('translateById')
            ->willReturnCallback(function (string $id, array $arguments, $quantity, Locale $locale, string $sourceName, string $packageKey) use ($translation): ?string {
                self::assertEquals(new Locale('de'), $locale);
                self::assertSame(['BackendModule/KeyManagement', 'NEOSidekick.AiAssistant'], [$sourceName, $packageKey]);
                if ($translation === null) {
                    return null;
                }

                return match ($id) {
                    'signingKey.regenerate.success' => $translation,
                    default => null,
                };
            });

        $this->setProtectedProperty($controller, 'response', $response ?? new ActionResponse());
        $this->setProtectedProperty($controller, 'agentKeyPairService', $keyPairService);
        $this->setProtectedProperty($controller, 'agentSigningKeyPushService', $pushService);
        $this->setProtectedProperty($controller, 'securityContext', $securityContext);
        $this->setProtectedProperty($controller, 'uriBuilder', $uriBuilder);
        $this->setProtectedProperty($controller, 'controllerContext', $controllerContext);
        $this->setProtectedProperty($controller, 'userService', $userService);
        $this->setProtectedProperty($controller, 'translator', $translator);

        return $controller;
    }

    private function setProtectedProperty(object $object, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($this->declaringClass(KeyManagementController::class, $propertyName), $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }

    /**
     * Properties like `view`, `uriBuilder` and `controllerContext` are declared on Flow's base
     * controllers, not on KeyManagementController.
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

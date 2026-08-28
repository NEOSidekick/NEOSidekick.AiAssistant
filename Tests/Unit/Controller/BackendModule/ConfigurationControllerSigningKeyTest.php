<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller\BackendModule;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Error\Messages\Error;
use Neos\Error\Messages\Message;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\FlashMessage\FlashMessageContainer;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The Configuration module shows administrators this installation's signing key and lets
 * them regenerate it. Load-bearing properties: the render never generates a keypair (RSA
 * generation in a backend request would stall the module), a missing or unreadable keypair
 * is a displayable state rather than an error page, the panel is gated on the administrator
 * role, and the regenerate action does nothing but delegate to the shared rotation.
 */
class ConfigurationControllerSigningKeyTest extends TestCase
{
    private const EMPTY_DETAILS = [
        'exists' => false,
        'fingerprint' => '',
        'status' => 'none',
        'pushedAt' => '',
        'regenerateIncomplete' => false,
        'relabelPending' => false,
        'registeredDomain' => null,
        'currentDomain' => null,
        'domainConflict' => false,
    ];

    /**
     * The panel identifies the key by its fingerprint alone; the raw key id stays a CLI and
     * log value.
     *
     * @test
     */
    public function theFingerprintIsExposedWhenAKeypairExists(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->expects(self::never())->method('getKeyId');
        $keyPairService->method('getFingerprint')->willReturn('6A:6E:0A:3B');

        self::assertSame(
            [
                'exists' => true,
                'fingerprint' => '6A:6E:0A:3B',
                'status' => 'confirmed',
                'pushedAt' => '2026-08-17T10:00:00+00:00',
                'regenerateIncomplete' => false,
                'relabelPending' => false,
                'registeredDomain' => null,
                'currentDomain' => null,
                'domainConflict' => false,
            ],
            $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'confirmed', 'pushedAt' => '2026-08-17T10:00:00+00:00', 'registeredDomain' => null])
        );
    }

    /**
     * The status the backend last reported is passed through verbatim - a key revoked in
     * the backend must be visible as such, not collapsed into "pending".
     *
     * @test
     */
    public function theRecordedPushStatusIsPassedThroughVerbatim(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('getFingerprint')->willReturn('6A:6E:0A:3B');

        $details = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'revoked', 'pushedAt' => '2026-08-17T10:00:00+00:00', 'registeredDomain' => null]);

        self::assertSame('revoked', $details['status']);

        $reenrolled = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'reenrolled', 'pushedAt' => '2026-08-17T10:00:00+00:00', 'registeredDomain' => null]);

        self::assertSame('reenrolled', $reenrolled['status']);
    }

    /**
     * The banner is driven by the pending pair in the row, never by a recorded outcome.
     *
     * @test
     */
    public function anIncompleteRegenerationIsReportedFromTheStoredPendingPair(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('hasPendingKeyPair')->willReturn(true);
        $keyPairService->method('isPendingRelabel')->willReturn(true);
        $keyPairService->method('getFingerprint')->willReturn('6A:6E:0A:3B');

        $details = $this->invokeGetSigningKeyDetails($keyPairService);

        self::assertTrue($details['regenerateIncomplete']);
        self::assertTrue($details['relabelPending']);
    }

    /** @test */
    public function noKeypairIsReportedAsAStateAndNeverGeneratesOne(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(false);
        $keyPairService->expects(self::never())->method('getFingerprint');
        $keyPairService->expects(self::never())->method('getPublicKeyPem');

        self::assertSame(self::EMPTY_DETAILS, $this->invokeGetSigningKeyDetails($keyPairService));
    }

    /**
     * Unreadable includes a split pair - a row whose private and public key do not belong
     * together. It is its own state, not "no key": the row exists and is restorable, and the
     * panel needs it to offer the re-enrolment checkbox.
     *
     * @test
     */
    public function anUnusableKeypairIsItsOwnStateRatherThanTheNoKeyOne(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(false);
        $keyPairService->method('hasPendingKeyPair')->willReturn(true);
        $keyPairService->expects(self::never())->method('getFingerprint');

        $details = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'confirmed', 'pushedAt' => '2026-08-17T10:00:00+00:00', 'registeredDomain' => null]);

        self::assertTrue($details['exists']);
        self::assertSame('unusable', $details['status']);
        self::assertSame('', $details['fingerprint']);
        self::assertTrue($details['regenerateIncomplete'], 'the pending banner survives the unusable live pair');
    }

    /**
     * A usable pair whose fingerprint still cannot be derived stays a displayable state instead
     * of a 500 - every question to the keypair service sits inside the same try.
     *
     * @test
     */
    public function anUnreadableFingerprintIsReportedAsAStateInsteadOfBubblingUp(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('getFingerprint')->willThrowException(new RuntimeException('the stored agent signing keys do not belong together', 1755300016));

        self::assertSame(self::EMPTY_DETAILS, $this->invokeGetSigningKeyDetails($keyPairService));
    }

    /**
     * A row that cannot be READ (the database is briefly unreachable) is not a reason to take the
     * whole module down: the first question to the keypair service is asked before the live
     * pair is, so it too must degrade to the "no key" details instead of surfacing as a 500.
     *
     * @test
     */
    public function anUnreadableSigningKeyRowDegradesToTheNoKeyDetailsInsteadOfFailingTheRender(): void
    {
        $storageFailure = new AgentSigningKeyStorageException('The agent signing keypair could not be loaded from the database.', 1757000012);

        $failingOnThePendingQuestion = $this->createMock(AgentKeyPairService::class);
        $failingOnThePendingQuestion->method('hasPendingKeyPair')->willThrowException($storageFailure);
        self::assertSame(self::EMPTY_DETAILS, $this->invokeGetSigningKeyDetails($failingOnThePendingQuestion));

        $failingOnTheKeyQuestion = $this->createMock(AgentKeyPairService::class);
        $failingOnTheKeyQuestion->method('hasKeyPair')->willThrowException($storageFailure);
        self::assertSame(self::EMPTY_DETAILS, $this->invokeGetSigningKeyDetails($failingOnTheKeyQuestion));
    }

    /** @test */
    public function indexActionAssignsTheAdministratorFlagFromTheSecurityContext(): void
    {
        foreach ([true, false] as $isAdministrator) {
            $assigned = $this->renderIndex($isAdministrator, new FlashMessageContainer());

            self::assertSame($isAdministrator, $assigned['isAdministrator']);
            self::assertSame('/neos/ai-assistant/configuration?regenerate', $assigned['regenerateActionUri']);
            self::assertSame('csrf-token', $assigned['csrfToken']);
            self::assertNull($assigned['regenerateFailure']);
            self::assertNull($assigned['regenerateRejectionReason']);
        }
    }

    /**
     * The failure of the regeneration this render follows arrives through the flash message
     * container; it is read raw, so a '%' in the backend's answer cannot break the render, and
     * it is consumed, so a reload does not show it again - while messages of other origins stay.
     * The rejection reason travels as the message title.
     *
     * @test
     */
    public function indexActionConsumesOnlyTheRegenerationFailureFromTheFlashMessages(): void
    {
        $container = new FlashMessageContainer();
        $unrelated = new Message('unrelated');
        $container->addMessage($unrelated);
        $container->addMessage(new Error('rejected with 100% certainty', ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE, [], 'chain_domain_mismatch'));

        $assigned = $this->renderIndex(true, $container);

        self::assertSame('rejected with 100% certainty', $assigned['regenerateFailure']);
        self::assertSame('chain_domain_mismatch', $assigned['regenerateRejectionReason']);
        self::assertSame([$unrelated], $container->getMessagesAndFlush(), 'the unrelated message is kept for whoever renders it');
    }

    /** @test */
    public function aRegenerationFailureWithoutAReasonAssignsNone(): void
    {
        $container = new FlashMessageContainer();
        $container->addMessage(new Error('Connection timed out', ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE));

        $assigned = $this->renderIndex(true, $container);

        self::assertSame('Connection timed out', $assigned['regenerateFailure']);
        self::assertNull($assigned['regenerateRejectionReason']);
    }

    /**
     * The only sign of a successful regeneration is the flash message: the fingerprint on the
     * panel changes, but nothing else does. Its code must differ from the failure code, which
     * indexAction() consumes into the red box.
     *
     * @test
     */
    public function regenerateSigningKeyActionDelegatesToTheSharedRotationAndConfirmsTheSuccess(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->expects(self::never())->method('generateKeyPair');
        $controller = $this->createController($pushService, $keyPairService);

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['Der Signaturschlüssel wurde erneuert.', '', Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
        self::assertNotSame(ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE);
    }

    /**
     * The Neos chrome renders flash messages untranslated, so an untranslatable body must not
     * reach the administrator as a translation id.
     *
     * @test
     */
    public function theSuccessMessageFallsBackToEnglishWhenTheUnitIsMissing(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService, $this->createMock(AgentKeyPairService::class), translation: null);

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The signing key was regenerated. This installation now identifies itself with the new key.', '', Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /** @test */
    public function anUnconfirmedRegenerationWarnsInsteadOfConfirming(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('revoked', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService, $this->createMock(AgentKeyPairService::class));

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['Der Schlüssel wurde erneuert, NEOSidekick meldet ihn als revoked.', '', Message::SEVERITY_WARNING, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /** @test */
    public function theUnconfirmedMessageFallsBackToEnglishNamingTheStatus(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::success('revoked', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService, $this->createMock(AgentKeyPairService::class), translation: null);

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The signing key was regenerated, but NEOSidekick reports it as revoked. Regenerate the key again to re-register this installation; connected tools will have to be reconnected.', '', Message::SEVERITY_WARNING, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /** @test */
    public function aFailedRegenerationIsCarriedToTheNextRenderAsAFlashMessage(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: chain_domain_mismatch', 'new-kid', 'chain_domain_mismatch'));
        $controller = $this->createController($pushService, $this->createMock(AgentKeyPairService::class));

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The NEOSidekick backend rejected the signing key with status 422: chain_domain_mismatch', 'chain_domain_mismatch', Message::SEVERITY_ERROR, ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * The regeneration would have to announce the successor unchained, which enrols this
     * installation anew - so it stops before touching anything, and says why.
     *
     * @test
     */
    public function anUnusableStoredKeyAbortsTheRegenerationInsteadOfReenrollingSilently(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::never())->method('rotateKeyPair');
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(false);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction();

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, $title, $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('the stored key is unusable', $body);
        self::assertStringContainsString('backup', $body);
        self::assertSame([Message::SEVERITY_ERROR, ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE], [$severity, $code]);
        self::assertSame('', $title);
    }

    /**
     * The administrator's explicit re-enrolment: the checkbox reaches the rotation, and the
     * outcome is reported as a re-enrolment rather than as the generic success.
     *
     * @test
     */
    public function theReenrolmentCheckboxIsPassedToTheRotationAndReportedAsSuch(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, false, true)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-2'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'reenrolled', 'pushedAt' => '2026-09-04T10:00:00+00:00', 'registeredDomain' => null]);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(false);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(true);

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['Registered as a new installation. Connected tools had to be reconnected with the new address.', '', Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * What the push recorded decides the wording, not what the box asked for: a key that turned
     * out chainable rotates the lineage as usual, so the generic success is the honest report.
     *
     * @test
     */
    public function aTickedBoxOnAKeyThatBecameUsableAgainReportsAChainedRotation(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, false, true)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-1'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '2026-09-04T10:00:00+00:00', 'registeredDomain' => null]);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(true);

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['The signing key was regenerated. This installation now identifies itself with the new key.', '', Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * The moved-site button: the relabel reaches the rotation as the relabel flag - not as a
     * re-enrolment - and a confirmed outcome is reported with its own wording, because nothing
     * had to be reconnected.
     *
     * @test
     */
    public function theRelabelFormIsPassedToTheRotationAndReportedWithItsOwnOutcome(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, true, false)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-1'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'confirmed', 'pushedAt' => '2026-09-04T10:00:00+00:00', 'registeredDomain' => 'https://www.example.com']);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->expects(self::never())->method('clearPendingRelabel');
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(false, true);

        /** @phpstan-ignore-next-line the test double records the flash messages */
        self::assertSame(
            [['This installation is now registered under its current domain. Connected tools keep working.', '', Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE]],
            $controller->flashMessages
        );
    }

    /**
     * What the push actually recorded wins over what the form asked for: a relabel whose chain
     * key was already revoked went unchained and enrolled a new installation, so the connected
     * tools have to be reconnected - the relabel wording would claim the opposite.
     *
     * @test
     */
    public function aRelabelThatTheBackendAnsweredWithAReenrolmentIsReportedAsOne(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::once())->method('rotateKeyPair')->with(null, true, false)
            ->willReturn(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid-2'));
        $pushService->method('getPushStatus')->willReturn(['status' => 'reenrolled', 'pushedAt' => '', 'registeredDomain' => 'https://staging.example.com']);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->expects(self::never())->method('clearPendingRelabel');
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(false, true);

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, , $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('Registered as a new installation', $body);
        self::assertSame([Message::SEVERITY_OK, ConfigurationController::REGENERATION_SUCCEEDED_MESSAGE_CODE], [$severity, $code]);
    }

    /**
     * Disarming the relabel is best effort: the row may be unwritable for the very reason the
     * push failed, and the administrator must still see that failure rather than an error page.
     * The flag then stays armed, bounded by the automatic push's retry back-off.
     *
     * @test
     */
    public function aFailedRelabelWhoseDisarmingThrowsStillReportsTheFailureAndRedirects(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn(
            AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: kid_mismatch', 'new-kid', 'kid_mismatch')
        );
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('clearPendingRelabel')->willThrowException(new RuntimeException('the signing-key row could not be written'));
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(false, true);

        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, $title, $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('kid_mismatch', $body);
        self::assertSame(['kid_mismatch', Message::SEVERITY_ERROR, ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE], [$title, $severity, $code]);
        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
    }

    /**
     * Both flags together would arm the relabel flag on a pending pair that is announced
     * unchained, and the automatic push would later carry it into a chained retry - so the
     * hand-crafted POST that sends both is refused before anything is prepared or pushed.
     *
     * @test
     */
    public function askingForAReenrolmentAndARelabelAtOnceIsRefusedBeforeAnyRotation(): void
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->expects(self::never())->method('rotateKeyPair');
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->expects(self::never())->method('hasKeyPair');
        $keyPairService->expects(self::never())->method('clearPendingRelabel');
        $controller = $this->createController($pushService, $keyPairService, translation: null);

        $controller->regenerateSigningKeyAction(true, true);

        /** @phpstan-ignore-next-line the test double records the redirect */
        self::assertSame(['index'], $controller->redirects);
        /** @phpstan-ignore-next-line the test double records the flash messages */
        [$body, $title, $severity, $code] = $controller->flashMessages[0];
        self::assertStringContainsString('two different answers', $body);
        self::assertSame(['', Message::SEVERITY_ERROR, ConfigurationController::REGENERATION_FAILED_MESSAGE_CODE], [$title, $severity, $code]);
    }

    /**
     * The relabel flag sticks to the pending pair, and the automatic editor-authorize push would
     * transmit it chained with no administrator present - so a relabel NEOSidekick did not accept
     * disarms itself. A plain regeneration has nothing to disarm and must not touch the flag,
     * which may have been armed by the CLI.
     *
     * @test
     */
    public function aFailedRelabelClearsThePendingRelabelFlagAndAPlainFailureDoesNot(): void
    {
        $rejection = AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 422: kid_mismatch', 'new-kid', 'kid_mismatch');

        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('rotateKeyPair')->willReturn($rejection);
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->expects(self::once())->method('clearPendingRelabel');
        $this->createController($pushService, $keyPairService, translation: null)->regenerateSigningKeyAction(false, true);

        $plainPushService = $this->createMock(AgentSigningKeyPushService::class);
        $plainPushService->method('rotateKeyPair')->willReturn($rejection);
        $plainKeyPairService = $this->createMock(AgentKeyPairService::class);
        $plainKeyPairService->method('hasKeyPair')->willReturn(true);
        $plainKeyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $plainKeyPairService->expects(self::never())->method('clearPendingRelabel');
        $this->createController($plainPushService, $plainKeyPairService, translation: null)->regenerateSigningKeyAction();
    }

    /**
     * The panel must offer the copy and moved-site controls exactly when NEOSidekick would refuse
     * a chained rotation, so the comparison mirrors the backend's own host derivation: scheme,
     * port and case are noise, `www` is another host, and an unknown side is not a conflict.
     *
     * @test
     */
    public function theDomainConflictComparesHostsTheWayTheBackendDoes(): void
    {
        $keyPairService = $this->createMock(AgentKeyPairService::class);
        $keyPairService->method('hasKeyPair')->willReturn(true);
        $keyPairService->method('isLiveKeyPairUsable')->willReturn(true);
        $keyPairService->method('getFingerprint')->willReturn('AA:BB');

        $sameHost = [
            ['https://www.example.com', 'https://www.example.com'],
            ['http://www.example.com', 'https://www.example.com'],
            ['https://www.example.com:8443', 'https://www.example.com'],
            ['https://WWW.Example.COM', 'https://www.example.com'],
            ['https://www.example.com/neos', 'https://www.example.com'],
            ['www.example.com', 'https://www.example.com'],
        ];
        foreach ($sameHost as [$registeredDomain, $currentDomain]) {
            $details = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'confirmed', 'pushedAt' => '', 'registeredDomain' => $registeredDomain], $currentDomain);
            self::assertFalse($details['domainConflict'], $registeredDomain . ' is the same host as ' . $currentDomain);
            self::assertSame($registeredDomain, $details['registeredDomain']);
            self::assertSame($currentDomain, $details['currentDomain']);
        }

        $otherHost = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'confirmed', 'pushedAt' => '', 'registeredDomain' => 'https://www.example.com'], 'https://example.com');
        self::assertTrue($otherHost['domainConflict'], 'www is a moved site, not the same host');

        foreach ([[null, 'https://www.example.com'], ['https://www.example.com', null], [null, null], ['', 'https://www.example.com'], ['   ', 'https://www.example.com']] as [$registeredDomain, $currentDomain]) {
            $details = $this->invokeGetSigningKeyDetails($keyPairService, ['status' => 'confirmed', 'pushedAt' => '', 'registeredDomain' => $registeredDomain], $currentDomain);
            self::assertFalse($details['domainConflict'], 'an unknown side is not a conflict');
        }
    }

    /**
     * The module is reachable by every editor with CanUse, and a key nobody pushed cannot mint a
     * usable token anyway - so rendering it must never mint an identity. The row is the proof.
     *
     * @test
     */
    public function indexActionOnAKeylessInstallationNeverCreatesTheSigningKeyRow(): void
    {
        $repository = new InMemoryAgentSigningKeyRecordRepository();

        $assigned = $this->renderIndex(true, new FlashMessageContainer(), $this->createKeyPairService($repository));

        self::assertNull($repository->findInstallRecord(), 'the render generated a keypair');
        self::assertSame(self::EMPTY_DETAILS, $assigned['signingKey']);
    }

    /**
     * @return array<string, mixed> The variables indexAction assigned to the view
     */
    private function renderIndex(bool $isAdministrator, FlashMessageContainer $flashMessageContainer, ?AgentKeyPairService $keyPairService = null): array
    {
        if ($keyPairService === null) {
            $keyPairService = $this->createMock(AgentKeyPairService::class);
            $keyPairService->method('hasKeyPair')->willReturn(false);
        }
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn(['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null]);
        $controller = $this->createController($pushService, $keyPairService, $isAdministrator, $flashMessageContainer);

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
        bool $isAdministrator = true,
        ?FlashMessageContainer $flashMessageContainer = null,
        ?string $translation = 'Der Signaturschlüssel wurde erneuert.'
    ): ConfigurationController {
        $controller = new class () extends ConfigurationController {
            /**
             * @var array<int, string>
             */
            public array $redirects = [];

            /**
             * @var array<int, array{0: string, 1: string, 2: string, 3: int|null}>
             */
            public array $flashMessages = [];

            protected function redirect($actionName, $controllerName = null, $packageKey = null, array $arguments = [], $delay = 0, $statusCode = 303, $format = null)
            {
                $this->redirects[] = $actionName;
            }

            public function addFlashMessage($messageBody, $messageTitle = '', $severity = Message::SEVERITY_OK, array $messageArguments = [], $messageCode = null)
            {
                $this->flashMessages[] = [$messageBody, $messageTitle, $severity, $messageCode];
            }
        };

        $securityContext = $this->createMock(SecurityContext::class);
        $securityContext->method('hasRole')->willReturnCallback(fn (string $roleIdentifier): bool => $roleIdentifier === 'Neos.Neos:Administrator' && $isAdministrator);
        $securityContext->method('getCsrfProtectionToken')->willReturn('csrf-token');

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('reset')->willReturnSelf();
        $uriBuilder->method('uriFor')->with('regenerateSigningKey')->willReturn('/neos/ai-assistant/configuration?regenerate');

        $controllerContext = $this->createMock(ControllerContext::class);
        $controllerContext->method('getFlashMessageContainer')->willReturn($flashMessageContainer ?? new FlashMessageContainer());

        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('domain')->willReturn('https://neos.example.com');

        $agentTokenService = $this->createMock(AgentTokenService::class);
        $agentTokenService->method('generateEmbedToken')->willReturn('embed-token');

        $userService = $this->createMock(UserService::class);
        $userService->method('getInterfaceLanguage')->willReturn('de');

        $translator = $this->createMock(Translator::class);
        $translator->method('translateById')
            ->willReturnCallback(function (string $id, array $arguments, $quantity, Locale $locale, string $sourceName, string $packageKey) use ($translation): ?string {
                self::assertEquals(new Locale('de'), $locale);
                self::assertSame(['BackendModule/Configuration', 'NEOSidekick.AiAssistant'], [$sourceName, $packageKey]);
                if ($translation === null) {
                    return null;
                }

                return match ($id) {
                    'signingKey.regenerate.success' => $translation,
                    'signingKey.regenerate.unconfirmed' => 'Der Schlüssel wurde erneuert, NEOSidekick meldet ihn als ' . $arguments[0] . '.',
                    default => null,
                };
            });

        $this->setProtectedProperty($controller, 'agentKeyPairService', $keyPairService);
        $this->setProtectedProperty($controller, 'agentSigningKeyPushService', $pushService);
        $this->setProtectedProperty($controller, 'securityContext', $securityContext);
        $this->setProtectedProperty($controller, 'uriBuilder', $uriBuilder);
        $this->setProtectedProperty($controller, 'controllerContext', $controllerContext);
        $this->setProtectedProperty($controller, 'neosidekickInternalHelper', $internalHelper);
        $this->setProtectedProperty($controller, 'agentTokenService', $agentTokenService);
        $this->setProtectedProperty($controller, 'logger', $this->createMock(LoggerInterface::class));
        $this->setProtectedProperty($controller, 'userService', $userService);
        $this->setProtectedProperty($controller, 'translator', $translator);
        $this->setProtectedProperty($controller, 'apiKey', 'test-api-key');
        $this->setProtectedProperty($controller, 'apiDomain', 'https://api.neosidekick.test');

        return $controller;
    }

    /**
     * @param array{status: string, pushedAt: string, registeredDomain?: string|null} $pushStatus
     * @return array{exists: bool, fingerprint: string, status: string, pushedAt: string, regenerateIncomplete: bool, relabelPending: bool, registeredDomain: string|null, currentDomain: string|null, domainConflict: bool}
     */
    private function invokeGetSigningKeyDetails(AgentKeyPairService $keyPairService, array $pushStatus = ['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null], ?string $pushDomain = null): array
    {
        $pushService = $this->createMock(AgentSigningKeyPushService::class);
        $pushService->method('getPushStatus')->willReturn($pushStatus);
        $pushService->method('getPushDomain')->willReturn($pushDomain);

        $controller = new ConfigurationController();
        $this->setProtectedProperty($controller, 'agentKeyPairService', $keyPairService);
        $this->setProtectedProperty($controller, 'agentSigningKeyPushService', $pushService);

        $method = new ReflectionMethod(ConfigurationController::class, 'getSigningKeyDetails');
        $method->setAccessible(true);

        return $method->invoke($controller);
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

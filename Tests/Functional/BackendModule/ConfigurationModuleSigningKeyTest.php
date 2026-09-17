<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\BackendModule;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Dispatcher;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\Doctrine\Exception\DatabaseStructureException;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The signing-key panel of the Configuration module, in two halves.
 *
 * Rendering: the module's Fusion is rendered for real, so a broken prototype or a missing
 * view variable fails here instead of in an administrator's browser - and the panel must
 * exist for administrators only.
 *
 * The action: the regenerate POST is routed to the real Neos module route and dispatched
 * through Flow's dispatcher (firewall with CSRF check, Neos' ModuleController, the module
 * sub-request), so the privilege (NEOSidekick.AiAssistant:ManageSigningKey, administrators
 * only) and the pending-pair rotation protocol are exercised end to end against a stubbed
 * NEOSidekick. The test browser is not used for the POST: every browser request opens a new
 * session, whose security context does not hold this test's CSRF token.
 */
class ConfigurationModuleSigningKeyTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    private const MODULE_URI = 'http://localhost/neos/ai-assistant/configuration';

    private const REGENERATE_ACTION_URI = '/neos/ai-assistant/configuration?moduleArguments%5B%40action%5D=regenerateSigningKey';

    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $requestHistory = [];

    private ?AgentSigningKeyPushService $originalPushService = null;

    /**
     * The panel copy is asserted in English; the process-wide I18n configuration is put back
     * afterwards so no later test translates in the wrong language.
     */
    private ?Locale $localeToRestore = null;

    public function setUp(): void
    {
        parent::setUp();
        $i18nConfiguration = $this->objectManager->get(I18nService::class)->getConfiguration();
        $this->localeToRestore = $i18nConfiguration->getCurrentLocale();
        $i18nConfiguration->setCurrentLocale(new Locale('en'));
        $this->requestHistory = [];
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        if ($this->localeToRestore !== null) {
            $this->objectManager->get(I18nService::class)->getConfiguration()->setCurrentLocale($this->localeToRestore);
        }
        if ($this->originalPushService !== null) {
            $this->objectManager->setInstance(AgentSigningKeyPushService::class, $this->originalPushService);
            $this->inject($this->objectManager->get(ConfigurationController::class), 'agentSigningKeyPushService', $this->originalPushService);
        }
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * The fingerprint is the only key identifier on the panel: the raw key id it is derived
     * from stays a CLI and log value.
     *
     * @test
     */
    public function theModuleRendersTheFingerprintOfAnExistingKeypairAndNotTheRawKeyId(): void
    {
        $output = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => '6A:6E:0A:3B:6E:0B:C7:A0:12:7A:00:70:0B:3E:DC:6A:95:2B:72:2C:60:58:4C:E9:59:D5:4E:82:D6:83:C3:34',
            'status' => 'pending',
            'pushedAt' => '2026-08-17T10:00:00+00:00',
        ]);

        self::assertStringContainsString('6A:6E:0A:3B:6E:0B:C7:A0', $output);
        self::assertStringNotContainsString('6a6e0a3b6e0bc7a0127a0070', $output);
        self::assertStringNotContainsString('Key ID', $output);
        self::assertStringContainsString('monospace', $output);
        self::assertStringContainsString('2026-08-17T10:00:00+00:00', $output);
        // The status is rendered through a translated label, not as the raw backend value.
        self::assertStringNotContainsString('>pending<', $output);
        self::assertStringNotContainsString('signingKey.status', $output);
    }

    /**
     * An unknown backend status must still render: the panel falls back to showing the raw
     * status rather than a missing-label error.
     *
     * @test
     */
    public function anUnknownPushStatusFallsBackToTheRawValue(): void
    {
        $output = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'something-new',
            'pushedAt' => '',
        ]);

        self::assertStringContainsString('something-new', $output);
    }

    /** @test */
    public function theModuleReportsAMissingKeypairAsAStateInsteadOfAnEmptyPanel(): void
    {
        $output = $this->renderConfigurationModule(['exists' => false]);

        self::assertStringContainsString('agentkey:generate', $output);
    }

    /**
     * A minted token reaches the iframe URL encoded; an install that mints none renders the
     * same iframe without any trace of the parameter.
     *
     * @test
     */
    public function theEmbedTokenIsAppendedToTheIframeUrlOnlyWhenItWasMinted(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiJ9.payload+slash/value.signature';

        $output = $this->renderConfigurationModule(['exists' => false], $token);

        self::assertStringContainsString('&amp;embedToken=' . rawurlencode($token), $output);

        $withoutToken = $this->renderConfigurationModule(['exists' => false], null);

        self::assertStringNotContainsString('embedToken', $withoutToken);
    }

    /**
     * Nothing else lives in that panel, so an editor sees nothing about keys at all - not the
     * fingerprint, not the button, not the CSRF token.
     *
     * @test
     */
    public function theSigningKeyPanelIsNotRenderedForAnEditor(): void
    {
        $output = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB:CC:DD', 'status' => 'confirmed', 'pushedAt' => '2026-08-17T10:00:00+00:00'],
            null,
            isAdministrator: false
        );

        self::assertStringNotContainsString('data-signing-key-panel', $output);
        self::assertStringNotContainsString('AA:BB:CC:DD', $output);
        self::assertStringNotContainsString('__csrfToken', $output);
        self::assertStringNotContainsString('regenerateSigningKey', $output);
        self::assertStringContainsString('<iframe', $output, 'the settings iframe is still there');
    }

    /** @test */
    public function theRegenerateFormPostsTheCsrfTokenToTheActionUriAndAsksForConfirmation(): void
    {
        $output = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB:CC:DD', 'status' => 'confirmed', 'pushedAt' => '']
        );

        self::assertStringContainsString('data-signing-key-panel', $output);
        self::assertStringContainsString('AA:BB:CC:DD', $output);
        self::assertStringContainsString(self::REGENERATE_ACTION_URI, $output, 'the form posts to the regenerate action');
        self::assertStringContainsString('csrf-token-value', $output, 'the CSRF token is carried');
        self::assertStringContainsString('__csrfToken', $output);
        self::assertStringContainsString('Regenerate the signing key?', $output, 'the confirmation text');
        self::assertStringContainsString('every connected tool must be reconnected', $output);
        self::assertStringContainsString('Regenerate key', $output);
        self::assertStringNotContainsString('agentkey:generate --force', $output, 'no CLI footnote');
        self::assertStringNotContainsString('data-signing-key-regenerate-incomplete', $output);
        self::assertStringNotContainsString('data-signing-key-regenerate-failed', $output);
    }

    /**
     * One banner per event: while a pending pair exists only the "incomplete" banner renders,
     * carrying the backend's answer - verbatim, which means escaped.
     *
     * @test
     */
    public function whileAPendingPairExistsOnlyTheIncompleteBannerRendersCarryingTheReason(): void
    {
        $output = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true],
            null,
            regenerateFailure: 'rejected with status 422: <b>chain_domain_mismatch</b>'
        );

        self::assertStringNotContainsString('data-signing-key-regenerate-failed', $output);
        self::assertStringContainsString('data-signing-key-regenerate-incomplete', $output);
        self::assertStringContainsString('Regeneration incomplete.', $output);
        self::assertStringContainsString('(rejected with status 422: &lt;b&gt;chain_domain_mismatch&lt;/b&gt;)', $output);
        self::assertStringNotContainsString('<b>chain_domain_mismatch</b>', $output);
        self::assertStringContainsString('no third key is created', $output);
        self::assertStringNotContainsString('registered for another domain', $output, 'no hint without the parsed reason');
        self::assertStringNotContainsString('re-registers the installation', $output);

        $bannerOnly = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true]
        );
        self::assertStringContainsString('(no confirmation was received)', $bannerOnly);
        self::assertStringNotContainsString('data-signing-key-regenerate-failed', $bannerOnly);
    }

    /** @test */
    public function theIncompleteBannerAppendsTheDomainMismatchHintAndTheRelabelNotice(): void
    {
        $withHint = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true],
            null,
            regenerateFailure: 'Signing key rejected. (chain_domain_mismatch)',
            regenerateRejectionReason: 'chain_domain_mismatch'
        );
        self::assertStringContainsString('registered for another domain', $withHint);
        self::assertStringContainsString('tick the re-enrolment box above and press Regenerate key', $withHint, 'the copy case points at the checkbox');
        self::assertStringContainsString('Re-register under the new domain', $withHint, 'the moved-site case points at the button');
        self::assertStringNotContainsString('--relabel', $withHint, 'the panel never prints the command');
        self::assertStringNotContainsString('DELETE FROM', $withHint, 'and never a SQL recipe');
        self::assertStringNotContainsString('re-registers the installation', $withHint);

        $relabelling = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true, 'relabelPending' => true]
        );
        self::assertStringContainsString('This regeneration also re-registers the installation under its current domain.', $relabelling);
        self::assertStringNotContainsString('registered for another domain', $relabelling);

        $otherReason = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true],
            null,
            regenerateFailure: 'Signing key rejected. (kid_mismatch)',
            regenerateRejectionReason: 'kid_mismatch'
        );
        self::assertStringNotContainsString('registered for another domain', $otherReason);
    }

    /**
     * Without a pending pair the failure box renders, carrying the backend's answer escaped.
     * The answer names the unreachable target itself, so the panel adds no reachability hint.
     *
     * @test
     */
    public function withoutAPendingPairTheFailureBoxRendersTheAnswerEscaped(): void
    {
        $output = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => ''],
            null,
            regenerateFailure: 'could not be regenerated: <b>disk full</b>'
        );

        self::assertStringContainsString('data-signing-key-regenerate-failed', $output);
        self::assertStringContainsString('The key was not regenerated.', $output);
        self::assertStringContainsString('«could not be regenerated: &lt;b&gt;disk full&lt;/b&gt;»', $output);
        self::assertStringNotContainsString('<b>disk full</b>', $output);
        self::assertStringContainsString('Try again in a few minutes.', $output);
        self::assertStringNotContainsString('can reach', $output);
        self::assertStringNotContainsString('data-signing-key-regenerate-incomplete', $output);
    }

    /**
     * A live key that is missing or unreadable must not hide what the administrator needs most:
     * the pending banner and the failure box.
     *
     * @test
     */
    public function theBannersRenderEvenWhenTheLiveKeyIsUnreadable(): void
    {
        $pending = $this->renderConfigurationModule(['exists' => false, 'regenerateIncomplete' => true]);
        self::assertStringContainsString('data-signing-key-regenerate-incomplete', $pending);
        self::assertStringContainsString('agentkey:generate', $pending, 'the missing-key state is still explained');

        $failed = $this->renderConfigurationModule(['exists' => false], null, regenerateFailure: 'could not be regenerated');
        self::assertStringContainsString('data-signing-key-regenerate-failed', $failed);
    }

    /**
     * Every status keeps its own unit - the panel looks the label up dynamically - and each is
     * one line: the re-enrolled state no longer carries a dated title.
     *
     * @test
     */
    public function theStatusTextsAreRendered(): void
    {
        $none = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'none', 'pushedAt' => '']);
        self::assertStringContainsString('Not transmitted to NEOSidekick yet; this happens the next time the assistant is opened.', $none);

        $pending = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'pending', 'pushedAt' => '']);
        self::assertStringContainsString('Transmitted, not registered by NEOSidekick yet; retried the next time an editor authorizes.', $pending);

        $confirmed = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '']);
        self::assertStringContainsString('Registered', $confirmed);
        self::assertStringNotContainsString('Registered as a new installation', $confirmed);

        $revoked = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'revoked', 'pushedAt' => '']);
        self::assertStringContainsString('No longer accepted by NEOSidekick.', $revoked);

        $reenrolled = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'reenrolled', 'pushedAt' => '']);
        self::assertStringContainsString('Registered as a new installation. Connected tools had to be reconnected with the new address.', $reenrolled);

        $unusable = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => '', 'status' => 'unusable', 'pushedAt' => '']);
        self::assertStringContainsString('The stored key is unusable', $unusable);
        self::assertStringContainsString('restore the signing-key row from a backup', $unusable);
    }

    /**
     * Re-enrolment orphans every connected tool, so the checkbox that asks for it exists only
     * where it is the remaining way out - an unusable key, a revoked lineage, a chained rotation
     * NEOSidekick just refused as coming from another host, or a host NEOSidekick does not accept
     * for this installation - and never next to a healthy key on a registered host.
     *
     * The relabel form is the other half of those last two states: it is the moved-site answer
     * where the checkbox is the copy answer, and it never renders on an unusable key, which
     * cannot chain the relabel in the first place.
     *
     * @test
     */
    public function theReenrolmentCheckboxIsOfferedOnlyOnAnUnusableKey(): void
    {
        $unusable = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => '', 'status' => 'unusable', 'pushedAt' => '']);

        self::assertStringContainsString('name="moduleArguments[reenrol]"', $unusable);
        self::assertStringContainsString('type="checkbox"', $unusable);
        self::assertStringContainsString('Enrol this installation as a new one', $unusable);
        self::assertStringContainsString('every tool connected to this installation must be reconnected', $unusable);
        self::assertStringNotContainsString('moduleArguments[relabel]', $unusable, 'an unusable key cannot chain a relabel');

        $revoked = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'revoked', 'pushedAt' => '']);
        self::assertStringContainsString('name="moduleArguments[reenrol]"', $revoked, 'a revoked lineage can only be left by enrolling anew');
        self::assertStringContainsString('Tick re-enrol below and regenerate to start a new installation; connected tools will have to be reconnected.', $revoked);
        self::assertStringNotContainsString('Regenerate the key to re-register', $revoked, 'a bare regeneration of a revoked lineage is refused, so the copy no longer suggests it');
        self::assertStringNotContainsString('moduleArguments[relabel]', $revoked);

        $refusedAsAnotherHost = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'regenerateIncomplete' => true],
            null,
            regenerateFailure: 'Signing key rejected. (chain_domain_mismatch)',
            regenerateRejectionReason: 'chain_domain_mismatch'
        );
        self::assertStringContainsString('name="moduleArguments[reenrol]"', $refusedAsAnotherHost);
        self::assertStringContainsString('name="moduleArguments[relabel]"', $refusedAsAnotherHost);

        $conflicting = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com'],
            'thisHost' => 'staging.example.com',
            'thisHostRegistered' => false,
            'hostsResult' => 'base_host_unknown',
        ]);
        self::assertStringContainsString('name="moduleArguments[reenrol]"', $conflicting);
        self::assertStringContainsString('name="moduleArguments[relabel]"', $conflicting);

        $conflictingButUnusable = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => '',
            'status' => 'unusable',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com'],
            'thisHost' => 'staging.example.com',
            'thisHostRegistered' => false,
            'hostsResult' => 'base_host_unknown',
        ]);
        self::assertStringContainsString('name="moduleArguments[reenrol]"', $conflictingButUnusable);
        self::assertStringNotContainsString('moduleArguments[relabel]', $conflictingButUnusable);

        foreach (['none', 'pending', 'confirmed', 'revoked', 'reenrolled'] as $status) {
            $output = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => $status, 'pushedAt' => '']);
            if ($status !== 'revoked') {
                self::assertStringNotContainsString('moduleArguments[reenrol]', $output, 'no re-enrolment checkbox next to a ' . $status . ' key');
            }
            self::assertStringNotContainsString('moduleArguments[relabel]', $output, 'no relabel form next to a ' . $status . ' key');
        }

        $keyless = $this->renderConfigurationModule(['exists' => false]);
        self::assertStringNotContainsString('moduleArguments[reenrol]', $keyless);
        self::assertStringNotContainsString('moduleArguments[relabel]', $keyless);
    }

    /**
     * The relabel is the one control that can revoke ANOTHER installation's key, so it posts to
     * the same guarded action as the regeneration, carries the CSRF token, and its confirm names
     * that consequence.
     *
     * @test
     */
    public function theRelabelFormPostsToTheRegenerateActionBehindAConfirmThatNamesTheRevocation(): void
    {
        $output = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com'],
            'thisHost' => 'staging.example.com',
            'thisHostRegistered' => false,
            'hostsResult' => 'base_host_unknown',
        ]);

        self::assertStringContainsString('data-signing-key-relabel-form', $output);
        self::assertStringContainsString(self::REGENERATE_ACTION_URI, $output, 'the relabel posts to the regenerate action');
        self::assertMatchesRegularExpression('/<input[^>]*name="moduleArguments\[relabel\]"[^>]*value="1"/', $output, 'the relabel flag travels as a hidden field');
        self::assertStringContainsString('__csrfToken', $output);
        self::assertStringContainsString('csrf-token-value', $output);
        self::assertStringContainsString('Re-register under the new domain', $output, 'the button label');
        self::assertStringContainsString("original's key is revoked", $output, 'the confirm names the consequence');
        self::assertStringNotContainsString('--relabel', $output, 'no CLI recipe in the panel');
    }

    /**
     * The proactive half: the host line names the address NEOSidekick calls this installation
     * at, the hosts it accepts, this host's standing and the last push result - and on a host it
     * does not accept, the remedy, before anyone presses anything.
     *
     * @test
     */
    public function theHostLineNamesTheAddressTheHostsThisHostsStandingAndTheLastPushResult(): void
    {
        $output = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com'],
            'thisHost' => 'staging.example.com',
            'thisHostRegistered' => false,
            'hostsResult' => 'base_host_unknown',
        ]);

        self::assertStringContainsString('data-signing-key-host-status="not-registered"', $output);
        self::assertStringContainsString('NEOSidekick calls this installation at https://www.example.com; registered hosts: https://www.example.com; this host: not registered; last push result: rejected', $output);
        self::assertStringContainsString('(base_host_unknown)', $output);
        self::assertStringContainsString('The assistant is refused on staging.example.com', $output);
        self::assertStringContainsString('add a Domain record for it in the Neos Sites module', $output);
        self::assertStringContainsString('tick the re-enrolment box and press Regenerate key', $output, 'the copy answer');
        self::assertStringContainsString('Re-register under the new domain', $output, 'the moved-site answer');
        self::assertStringContainsString('name="moduleArguments[reenrol]"', $output);
        self::assertStringContainsString('name="moduleArguments[relabel]"', $output);

        $agreeing = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com', 'https://academy.example.com'],
            'thisHost' => 'academy.example.com',
            'thisHostRegistered' => true,
            'hostsResult' => 'accepted',
        ]);
        self::assertStringContainsString('data-signing-key-host-status="registered"', $agreeing);
        self::assertStringContainsString('registered hosts: https://www.example.com, https://academy.example.com; this host: registered; last push result: accepted.', $agreeing);
        self::assertStringNotContainsString('The assistant is refused on', $agreeing, 'no remedy on a registered host');
        self::assertStringNotContainsString('moduleArguments[relabel]', $agreeing);
        self::assertStringNotContainsString('moduleArguments[reenrol]', $agreeing);

        $unknown = $this->renderConfigurationModule(['exists' => true, 'fingerprint' => 'AA:BB', 'status' => 'confirmed', 'pushedAt' => '', 'thisHost' => 'staging.example.com']);
        self::assertStringContainsString('data-signing-key-host-status="unknown"', $unknown);
        self::assertStringContainsString('NEOSidekick calls this installation at unknown; registered hosts: unknown; this host: unknown; last push result: unknown.', $unknown);
        self::assertStringNotContainsString('moduleArguments[relabel]', $unknown, 'no answer offers no moved-site button');
        self::assertStringNotContainsString('moduleArguments[reenrol]', $unknown);

        $emptySet = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => [],
            'thisHost' => 'www.example.com',
            'thisHostRegistered' => false,
            'hostsResult' => 'expired',
        ]);
        self::assertStringContainsString('registered hosts: none;', $emptySet);
        self::assertStringContainsString("check this server's clock", $emptySet);

        $unknownReason = $this->renderConfigurationModule([
            'exists' => true,
            'fingerprint' => 'AA:BB',
            'status' => 'confirmed',
            'pushedAt' => '',
            'hostStatusKnown' => true,
            'installAddress' => 'https://www.example.com',
            'registeredHosts' => ['https://www.example.com'],
            'thisHost' => 'www.example.com',
            'thisHostRegistered' => true,
            'hostsResult' => '<new_reason>',
        ]);
        self::assertStringContainsString('last push result: &lt;new_reason&gt;.', $unknownReason, 'a reason coined later is shown raw, escaped');

        $rejectedOnAKnownConflict = $this->renderConfigurationModule(
            [
                'exists' => true,
                'fingerprint' => 'AA:BB',
                'status' => 'confirmed',
                'pushedAt' => '',
                'regenerateIncomplete' => true,
                'hostStatusKnown' => true,
                'installAddress' => 'https://www.example.com',
                'registeredHosts' => ['https://www.example.com'],
                'thisHost' => 'staging.example.com',
                'thisHostRegistered' => false,
                'hostsResult' => 'base_host_unknown',
            ],
            null,
            regenerateFailure: 'Signing key rejected. (chain_domain_mismatch)',
            regenerateRejectionReason: 'chain_domain_mismatch'
        );
        self::assertStringContainsString('data-signing-key-host-status="not-registered"', $rejectedOnAKnownConflict);
        self::assertStringContainsString('registered for another domain', $rejectedOnAKnownConflict, 'the refusal keeps its own hint');
    }

    /** @test */
    public function anUnknownPushStatusIsRenderedEscaped(): void
    {
        $output = $this->renderConfigurationModule(
            ['exists' => true, 'fingerprint' => 'AA:BB', 'status' => '<script>alert(1)</script>', 'pushedAt' => '']
        );

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
    }

    /** @test */
    public function anEditorIsDeniedTheRegenerateAction(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Editor'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        try {
            $this->postRegenerate();
            self::fail('Expected the editor to be denied');
        } catch (AccessDeniedException $exception) {
            self::assertStringContainsString('regenerateSigningKeyAction', $exception->getMessage());
        }

        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        self::assertFalse($this->objectManager->get(AgentKeyPairService::class)->hasPendingKeyPair());
        self::assertSame(self::FIXTURE_KID, $this->objectManager->get(AgentKeyPairService::class)->getKeyId());
    }

    /**
     * The regeneration changes this installation's identity, and Flow's CSRF protection lets a
     * GET through as a safe method - so the verb itself is the gate: a GET is refused with 405
     * and rotates nothing, whoever crafted the link.
     *
     * @test
     */
    public function aGetOnTheRegenerateActionIsRefusedAndRotatesNothing(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $response = $this->getRegenerate();

        self::assertSame(405, $response->getStatusCode());
        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'no successor was even prepared');
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is untouched');
    }

    /** @test */
    public function anAdministratorRegeneratesTheKeyThroughTheModule(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $response = $this->postRegenerate();

        self::assertStringContainsString('ai-assistant/configuration', (string)$response->getRedirectUri(), 'back to the module index');

        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair was promoted on the 200');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId());

        $body = $this->lastRequestBody();
        self::assertSame(trim($keyPairService->getPublicKeyPem()), $body['public_key_pem']);
        self::assertSame(self::FIXTURE_KID, $body['chain_kid'], 'chained to the previous live key');
        $signature = base64_decode((string)$body['chain_signature'], true);
        self::assertNotFalse($signature);
        self::assertSame(1, openssl_verify($body['public_key_pem'], $signature, (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem'), OPENSSL_ALGO_SHA256));
        self::assertSame('https://www.example.com', $body['domain']);
        self::assertSame(['https://www.example.com'], $body['hosts']);
        $hostSignature = base64_decode((string)$body['hosts_signature'], true);
        self::assertNotFalse($hostSignature);
        self::assertSame(1, openssl_verify(
            AgentSigningKeyPushService::canonicalHostString($body['kid'], $body['hosts_signed_at'], $body['domain'], $body['hosts']),
            $hostSignature,
            $body['public_key_pem'],
            OPENSSL_ALGO_SHA256
        ), 'the host set is signed by the pushed (pending) key');

        self::assertSame('root-kid-1', $this->readSigningKeyColumn('pushinstallrootkid'));
        self::assertSame($keyPairService->getKeyId(), $this->readSigningKeyColumn('pushkid'));
    }

    /**
     * The MH3 case end to end: a stored pair that cannot vouch for a successor stops the
     * regeneration - no successor, no push, nothing that would orphan the connected tools -
     * until the administrator ticks the re-enrolment checkbox, which announces the new key
     * unchained on purpose.
     *
     * @test
     */
    public function anUnusableStoredKeyAbortsTheRegenerationUntilTheAdministratorAsksForAReenrolment(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->seedSigningKeyRecord(null, (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-9', 'new-kid')]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $this->postRegenerate();

        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'no successor was even prepared');
        self::assertNull($this->readSigningKeyColumn('pushkid'));

        $this->postRegenerate(['reenrol' => '1']);

        self::assertCount(1, $this->requestHistory);
        self::assertNull($this->lastRequestBody()['chain_kid'], 'a re-enrolment is announced unchained');
        self::assertNull($this->lastRequestBody()['chain_signature']);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the successor is the live pair now');
        self::assertSame(trim((string)$this->readSigningKeyColumn('publickeypem')), $this->lastRequestBody()['public_key_pem']);
    }

    /** @test */
    public function aRejectedRegenerationKeepsBothPairsAndASecondPressTransmitsTheSamePendingKey(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Signing key rejected.","reason":"chain_domain_mismatch"}'),
            new Response(200, [], 'not json at all'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $response = $this->postRegenerate();
        self::assertStringContainsString('ai-assistant/configuration', (string)$response->getRedirectUri(), 'a failure redirects back to the module too');
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'the pending pair waits');
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is untouched');
        self::assertNull($this->readSigningKeyColumn('pushkid'), 'a rejected push records no state');
        $pendingPublicKeyPem = trim((string)$this->readSigningKeyColumn('pendingpublickeypem'));

        $this->postRegenerate();
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'an unreadable 200 body keeps the pending pair');
        self::assertSame($pendingPublicKeyPem, $this->lastRequestBody()['public_key_pem'], 'the same pending key again');

        $this->postRegenerate();
        self::assertFalse($keyPairService->hasPendingKeyPair());
        self::assertSame($pendingPublicKeyPem, $this->lastRequestBody()['public_key_pem'], 'still the same key - no third one');
        self::assertSame($pendingPublicKeyPem, trim((string)$this->readSigningKeyColumn('publickeypem')));
        self::assertCount(3, $this->requestHistory);
    }

    /**
     * The module render is NOT a generation trigger: it is open to every editor, and a keypair
     * nobody pushed to NEOSidekick cannot mint a usable token - it would only be an inert
     * identity nobody asked for. A keyless installation is a state the panel reports.
     *
     * @test
     */
    public function openingTheModuleOnAKeylessInstallationCreatesNoRow(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->clearSigningKeyRecord();

        $this->stubNeosidekick([$this->confirmedResponse('root-kid-2', 'first-kid')]);

        $output = $this->getModuleIndex();

        self::assertSame(0, $this->countSigningKeyRecords(), 'rendering the module must never mint a key');
        self::assertCount(0, $this->requestHistory, 'and the panel\'s forced push does not run without a key');
        self::assertStringContainsString('agentkey:generate', $output, 'the keyless state is explained instead');
        self::assertStringNotContainsString('data-signing-key-hosts', $output, 'no host line without a key');
    }

    /**
     * The authorization flow is where a keyless installation DOES get its first keypair: it is
     * the one authenticated path that pushes the key right away, so the key never exists
     * without NEOSidekick learning of it. Having no predecessor, it is enrolled unchained.
     *
     * @test
     */
    public function theAuthorizationPushOnAKeylessInstallationCreatesTheRowAndTransmitsItUnchained(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-2', 'first-kid')]);
        $this->clearSigningKeyRecord();

        $result = $this->objectManager->get(AgentSigningKeyPushService::class)->pushIfNecessary();

        self::assertNotNull($result, 'a keyless installation with a push target must push');
        self::assertTrue($result->successful);
        self::assertSame(1, $this->countSigningKeyRecords(), 'the authorization created the installation key');

        $body = $this->lastRequestBody();
        self::assertSame(
            trim((string)$this->readSigningKeyColumn('publickeypem')),
            $body['public_key_pem'],
            'the row was written before the push, and it is that key which was transmitted'
        );
        self::assertNull($body['chain_kid'] ?? null, 'a first key has no predecessor to chain to');
        self::assertSame(
            AgentKeyPairService::deriveKeyId((string)$this->readSigningKeyColumn('publickeypem')),
            $this->readSigningKeyColumn('pushkid')
        );
    }

    /**
     * An installation whose database migration has not run yet must still open the module -
     * and explain the keyless state - rather than answer with a 500: the panel is where an
     * administrator would look for what is wrong. The whole render runs against a repository
     * whose every load fails the way it does in the field, so every question the module asks
     * (the pending pair, the relabel flag, the key, the push status, the embed token) is
     * covered, not just the first one.
     *
     * The missing table is handed in as the exception Flow's Query raises for it - its own
     * DatabaseStructureException, unchained - rather than by dropping the table here: the
     * functional database is SQLite, whose "no such table" wording Flow's Query does not
     * recognise as a structure problem, so a dropped table would exercise the test harness
     * instead of the production guard.
     *
     * @test
     */
    public function anInstallationWhoseMigrationHasNotRunRendersTheKeylessPanelInsteadOfFailing(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $repositoryWithoutTable = $this->createMock(AgentSigningKeyRecordRepository::class);
        $repositoryWithoutTable->method('findInstallRecord')->willThrowException(
            new DatabaseStructureException('A table or view seems to be missing from the database.', 1146)
        );

        $originalRepository = $this->replaceSigningKeyRecordRepository($repositoryWithoutTable);
        try {
            $output = $this->getModuleIndex();
        } finally {
            $this->replaceSigningKeyRecordRepository($originalRepository);
        }

        self::assertStringContainsString('agentkey:generate', $output, 'the keyless state is explained');
        self::assertStringNotContainsString('data-signing-key-regenerate-incomplete', $output);
        self::assertStringNotContainsString('data-signing-key-regenerate-failed', $output);
        self::assertStringContainsString('<iframe', $output, 'the rest of the module is intact');
    }

    /**
     * Renders the module through its real index action, so the render path - and only it - is
     * what the assertions see.
     */
    private function getModuleIndex(): string
    {
        $arguments = ['moduleArguments' => ['@action' => 'index']];
        $httpRequest = (new ServerRequest('GET', self::MODULE_URI))->withQueryParams($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }

        $response = new ActionResponse();
        $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest, $response);

        return (string)$response->getContent();
    }

    /**
     * @param array<string, mixed> $signingKey Partial details; missing keys take the empty state
     */
    private function renderConfigurationModule(array $signingKey, ?string $embedToken = null, bool $isAdministrator = true, ?string $regenerateFailure = null, ?string $regenerateRejectionReason = null): string
    {
        $signingKey = array_merge([
            'exists' => false,
            'fingerprint' => '',
            'status' => 'none',
            'pushedAt' => '',
            'regenerateIncomplete' => false,
            'relabelPending' => false,
            'hostStatusKnown' => false,
            'installAddress' => null,
            'registeredHosts' => [],
            'thisHost' => null,
            'thisHostRegistered' => false,
            'hostsResult' => null,
        ], $signingKey);

        $view = new FusionView();
        $view->setControllerContext($this->createControllerContext());
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule');
        $view->setFusionPath('NEOSidekick/AiAssistant/BackendModule/ConfigurationController/index');
        $view->assign('apiKey', 'test-api-key');
        $view->assign('apiDomain', 'https://api.neosidekick.com');
        $view->assign('siteDomain', '');
        $view->assign('signingKey', $signingKey);
        $view->assign('embedToken', $embedToken);
        $view->assign('isAdministrator', $isAdministrator);
        $view->assign('regenerateActionUri', self::REGENERATE_ACTION_URI);
        $view->assign('csrfToken', 'csrf-token-value');
        $view->assign('regenerateFailure', $regenerateFailure);
        $view->assign('regenerateRejectionReason', $regenerateRejectionReason);

        return (string)$view->render();
    }

    private function createControllerContext(): ControllerContext
    {
        $actionRequest = ActionRequest::fromHttpRequest(new ServerRequest('GET', 'https://neos.example.com/neos/management/ai-assistant/configuration'));
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($actionRequest);

        return new ControllerContext($actionRequest, new ActionResponse(), new Arguments(), $uriBuilder);
    }

    /**
     * What Flow's dispatch middleware would build from the module form's POST: the routed
     * request for the Neos module route, carrying the module action and the CSRF token.
     *
     * @param array<string, string> $moduleArguments What the form's own fields add to the POST
     * @throws AccessDeniedException When the privilege or the CSRF check denies the action
     */
    private function postRegenerate(array $moduleArguments = []): ActionResponse
    {
        $arguments = [
            'moduleArguments' => array_merge(['@action' => 'regenerateSigningKey'], $moduleArguments),
            '__csrfToken' => $this->securityContext->getCsrfProtectionToken(),
        ];
        $httpRequest = (new ServerRequest('POST', self::MODULE_URI, ['Content-Type' => 'application/x-www-form-urlencoded']))->withParsedBody($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }
        self::assertSame('Neos\\Neos\\Controller\\Backend\\ModuleController', $actionRequest->getControllerObjectName(), 'the Neos module route');

        $response = new ActionResponse();
        $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest, $response);

        return $response;
    }

    /**
     * Replaces the push service's HTTP client with a queue of canned NEOSidekick answers. The
     * double subclasses the proxied singleton, so Flow does not inject it - its collaborators
     * are copied over by hand.
     *
     * @param array<int, mixed> $queuedResponses
     */
    private function stubNeosidekick(array $queuedResponses): void
    {
        $handlerStack = HandlerStack::create(new MockHandler($queuedResponses));
        $handlerStack->push(Middleware::history($this->requestHistory));

        $stub = new class () extends AgentSigningKeyPushService {
            public ?Client $pushClient = null;

            protected function createPushClient(int $timeoutSeconds = 2): Client
            {
                return $this->pushClient ?? parent::createPushClient($timeoutSeconds);
            }
        };
        $stub->pushClient = new Client(['handler' => $handlerStack]);

        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('pluginVersion')->willReturn('1.2.3');
        $hostCollector = $this->createMock(AgentInstallHostCollector::class);
        $hostCollector->method('resolveInstallOrigin')->willReturn('https://www.example.com');
        $hostCollector->method('collectHosts')->willReturn(['https://www.example.com']);

        foreach ([
            'agentKeyPairService' => $this->objectManager->get(AgentKeyPairService::class),
            'agentSigningKeyRecordRepository' => $this->objectManager->get(AgentSigningKeyRecordRepository::class),
            'neosidekickInternalHelper' => $internalHelper,
            'agentInstallHostCollector' => $hostCollector,
            'logger' => $this->createMock(LoggerInterface::class),
            'apiKey' => 'test-api-key',
            'externalApiDomain' => 'https://api.neosidekick.test',
        ] as $propertyName => $value) {
            $property = new ReflectionProperty(AgentSigningKeyPushService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($stub, $value);
        }

        $this->originalPushService = $this->objectManager->get(AgentSigningKeyPushService::class);
        $this->objectManager->setInstance(AgentSigningKeyPushService::class, $stub);
        $this->inject($this->objectManager->get(ConfigurationController::class), 'agentSigningKeyPushService', $stub);
    }

    /**
     * The same module action reached with the verb a crafted link would use.
     */
    private function getRegenerate(): ActionResponse
    {
        $arguments = ['moduleArguments' => ['@action' => 'regenerateSigningKey']];
        $httpRequest = (new ServerRequest('GET', self::MODULE_URI))->withQueryParams($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }

        $response = new ActionResponse();
        $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest, $response);

        return $response;
    }

    private function createBackendAccount(string $roleIdentifier): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('module-signing-key-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $account->setRoles([$this->objectManager->get(PolicyService::class)->getRole($roleIdentifier)]);
        $this->objectManager->get(AccountRepository::class)->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Module', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }

    private function confirmedResponse(string $installRootKid, string $kid): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'confirmed',
            'kid' => $kid,
            'fingerprint' => 'AA:BB',
            'install_root_kid' => $installRootKid,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequestBody(): array
    {
        self::assertNotEmpty($this->requestHistory);
        /** @var Request $request */
        $request = $this->requestHistory[count($this->requestHistory) - 1]['request'];

        return json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}

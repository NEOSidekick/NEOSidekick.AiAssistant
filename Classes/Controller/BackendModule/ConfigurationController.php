<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @noinspection PhpUnused
 */
class ConfigurationController extends AbstractModuleController
{
    /**
     * Identifies the flash message that carries a failed regeneration's reason from
     * {@see regenerateSigningKeyAction} to the panel rendered by {@see indexAction}.
     */
    public const REGENERATION_FAILED_MESSAGE_CODE = 1756800010;

    /**
     * Distinct from {@see REGENERATION_FAILED_MESSAGE_CODE} so {@see consumeRegenerationFailure}
     * leaves it in the container for the Neos chrome. It also carries the warning for a
     * regeneration that came back unconfirmed, because the failure box would say "nothing has
     * changed".
     */
    public const REGENERATION_SUCCEEDED_MESSAGE_CODE = 1756800011;

    protected $defaultViewObjectName = FusionView::class;

    /**
     * This is needed for type hinting in the IDE
     *
     * @var FusionView
     */
    protected $view;

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @Flow\InjectConfiguration(path="Internal.apiDomain")
     * @var string
     */
    protected string $apiDomain;

    /**
     * @Flow\Inject
     * @var NEOSidekickInternalHelper
     */
    protected $neosidekickInternalHelper;

    /**
     * @Flow\Inject
     * @var AgentKeyPairService
     */
    protected $agentKeyPairService;

    /**
     * @Flow\Inject
     * @var AgentSigningKeyPushService
     */
    protected $agentSigningKeyPushService;

    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected $agentTokenService;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * @param FusionView $view
     *
     * @return void
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule');
    }

    public function indexAction(): void
    {
        $isAdministrator = $this->securityContext->hasRole('Neos.Neos:Administrator');
        $this->view->assign('apiKey', $this->apiKey);
        $this->view->assign('apiDomain', $this->apiDomain);
        $this->view->assign('siteDomain', $this->getSiteDomain());
        $this->view->assign('signingKey', $this->getSigningKeyDetails($isAdministrator ? $this->forceSigningKeyPush() : null));
        $this->view->assign('embedToken', $this->getEmbedToken());
        $this->view->assign('isAdministrator', $isAdministrator);
        $this->view->assign('regenerateActionUri', $this->uriBuilder->reset()->uriFor('regenerateSigningKey'));
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
        $regenerationFailure = $this->consumeRegenerationFailure();
        $this->view->assign('regenerateFailure', $regenerationFailure['message'] ?? null);
        $this->view->assign('regenerateRejectionReason', $regenerationFailure['reason'] ?? null);
    }

    /**
     * The regeneration changes this installation's identity, so it is only ever the answer to a
     * form submission: a GET - which Flow's CSRF protection lets through as a safe method - is
     * refused with 405 instead of rotating.
     *
     * @return void
     * @throws StopActionException
     */
    protected function initializeRegenerateSigningKeyAction(): void
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->throwStatus(405, null, 'The signing key is only regenerated on a POST from the module form.');
        }
    }

    /**
     * Regenerates this installation's signing key: the successor is transmitted to NEOSidekick
     * chained to the current key and replaces it once NEOSidekick registered it (any readable
     * 200); a failed push changes nothing, and a registered-but-unconfirmed answer is reported
     * as a warning (see AgentSigningKeyPushService::rotateKeyPair()).
     *
     * A stored keypair that cannot vouch for the successor aborts the regeneration: enrolling
     * this installation anew is the administrator's explicit decision, taken with the panel's
     * re-enrolment checkbox, because it orphans every connected tool.
     *
     * The panel's second form sends `$relabel` instead: it re-registers this installation under
     * the domain it answers as, for a site that moved. It keeps the identity and the connected
     * tools, and it skips NEOSidekick's clone rule - which is why the two flags are refused
     * together: a re-enrolment announces the successor unchained, so a relabel armed on it would
     * be carried into a later, chained push by the automatic editor-authorize path. A relabel
     * that NEOSidekick did not accept therefore disarms itself again before the failure is
     * reported ({@see AgentKeyPairService::clearPendingRelabel()}).
     *
     * Administrators only (Policy.yaml, NEOSidekick.AiAssistant:ManageSigningKey); both forms
     * carry Flow's CSRF token as the hidden `__csrfToken` field.
     *
     * @param bool $reenrol Enrol this installation as a new one when the stored key is unusable
     * @param bool $relabel Re-register this installation under the domain it answers as
     * @return void
     */
    public function regenerateSigningKeyAction(bool $reenrol = false, bool $relabel = false): void
    {
        if ($reenrol && $relabel) {
            $this->addFlashMessage(
                $this->translateRegenerationOutcome(
                    'signingKey.relabel.bothFlags',
                    [],
                    'The signing key was not regenerated: enrolling this installation as a new one and re-registering it under its current domain are two different answers to the same situation. Choose one of them and try again.'
                ),
                '',
                Message::SEVERITY_ERROR,
                [],
                self::REGENERATION_FAILED_MESSAGE_CODE
            );
            $this->redirect('index');

            return;
        }

        if (!$reenrol && $this->liveKeyPairIsUnusable()) {
            $this->addFlashMessage(
                $this->translateRegenerationOutcome(
                    'signingKey.regenerate.unusableAbort',
                    [],
                    'The signing key was not regenerated: the stored key is unusable, so nothing can vouch for a successor. Restore the signing-key row from a backup to keep this installation\'s identity, or tick the re-enrolment checkbox to enrol this installation as a new one.'
                ),
                '',
                Message::SEVERITY_ERROR,
                [],
                self::REGENERATION_FAILED_MESSAGE_CODE
            );
            $this->redirect('index');

            return;
        }

        $result = $this->agentSigningKeyPushService->rotateKeyPair(null, $relabel, $reenrol);
        if ($result->successful && $result->isConfirmed()) {
            // What the push actually recorded, not what was asked for: a ticked box on a key that
            // turned out chainable rotates the lineage as usual, and a relabel whose chain key was
            // already revoked went unchained and enrolled a new installation - its tools must be
            // reconnected, whatever was asked for. If the commit did not record a push at all,
            // the generic success wording stands in.
            $reenrolled = ($this->agentSigningKeyPushService->getPushStatus()['status'] ?? '') === 'reenrolled';
            if ($reenrolled) {
                $message = $this->translateRegenerationOutcome(
                    'signingKey.status.reenrolled',
                    [],
                    'Registered as a new installation. Connected tools had to be reconnected with the new address.'
                );
            } elseif ($relabel) {
                $message = $this->translateRegenerationOutcome(
                    'signingKey.relabel.success',
                    [],
                    'This installation is now registered under its current domain. Connected tools keep working.'
                );
            } else {
                $message = $this->translateRegenerationOutcome(
                    'signingKey.regenerate.success',
                    [],
                    'The signing key was regenerated. This installation now identifies itself with the new key.'
                );
            }
            $this->addFlashMessage($message, '', Message::SEVERITY_OK, [], self::REGENERATION_SUCCEEDED_MESSAGE_CODE);
        } elseif ($result->successful) {
            $status = (string)$result->status;
            $message = $this->translateRegenerationOutcome(
                'signingKey.regenerate.unconfirmed',
                [$status],
                'The signing key was regenerated, but NEOSidekick reports it as ' . $status . '. Regenerate the key again to re-register this installation; connected tools will have to be reconnected.'
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING, [], self::REGENERATION_SUCCEEDED_MESSAGE_CODE);
        } else {
            if ($relabel) {
                try {
                    // The flag sticks to the pending pair, and the automatic editor-authorize push
                    // would carry it into a chained retry with no administrator present.
                    $this->agentKeyPairService->clearPendingRelabel();
                } catch (RuntimeException $exception) {
                    // The flash below still reports the failure, and the flag then stays bounded by the retry back-off.
                }
            }
            $this->addFlashMessage((string)$result->errorMessage, (string)$result->rejectionReason, Message::SEVERITY_ERROR, [], self::REGENERATION_FAILED_MESSAGE_CODE);
        }

        $this->redirect('index');
    }

    /**
     * Whether a stored keypair exists that cannot chain a successor. A row that cannot be read
     * at all is not this state: the rotation itself reports that failure.
     */
    protected function liveKeyPairIsUnusable(): bool
    {
        try {
            return $this->agentKeyPairService->hasKeyPair() && !$this->agentKeyPairService->isLiveKeyPairUsable();
        } catch (RuntimeException $exception) {
            return false;
        }
    }

    /**
     * The regeneration outcome shown to the administrator, translated in their interface
     * language because the Neos chrome renders flash messages untranslated; a missing
     * translation unit falls back to the English source text.
     *
     * @param array<int, string> $arguments The values for the unit's {0}, {1}, ... placeholders
     * @param string $englishFallback The already-interpolated English source text
     */
    protected function translateRegenerationOutcome(string $unitId, array $arguments, string $englishFallback): string
    {
        $translated = $this->translator->translateById(
            $unitId,
            $arguments,
            null,
            new Locale($this->userService->getInterfaceLanguage()),
            'BackendModule/Configuration',
            'NEOSidekick.AiAssistant'
        );

        return $translated ?? $englishFallback;
    }

    /**
     * The failure of the regeneration this render follows, if any. Read raw (not rendered):
     * the text is the backend's verbatim answer and may contain anything, including '%'. The
     * backend's machine-readable rejection reason travels as the message title, which nothing
     * renders as a heading because the message is consumed here. Messages of other origins
     * stay in the container for whoever renders them.
     *
     * @return array{message: string, reason: string|null}|null
     */
    protected function consumeRegenerationFailure(): ?array
    {
        $failure = null;
        $flashMessageContainer = $this->controllerContext->getFlashMessageContainer();
        foreach ($flashMessageContainer->getMessagesAndFlush() as $message) {
            if ($message->getCode() !== self::REGENERATION_FAILED_MESSAGE_CODE) {
                $flashMessageContainer->addMessage($message);
                continue;
            }

            $failure = [
                'message' => $message->getMessage(),
                'reason' => $message->getTitle() === '' ? null : $message->getTitle(),
            ];
        }

        return $failure;
    }

    /**
     * Mints the short-lived embed token the settings iframe carries, so the embedded page knows
     * which editor - not merely which account - is looking at it.
     *
     * A failure (no signing keypair, legacy install) is not fatal: the token is omitted and the
     * module still renders, with the editor-scoped section hidden.
     *
     * @return string|null
     */
    protected function getEmbedToken(): ?string
    {
        try {
            return $this->agentTokenService->generateEmbedToken();
        } catch (AgentTokenException $exception) {
            $this->logger->warning(
                'NEOSidekick settings module could not mint an embed token: ' . $exception->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return null;
        }
    }

    /**
     * Opening the panel transmits the key - and with it this installation's host set - so a
     * Domain record added since the last push, or a set NEOSidekick rejected, is re-sent by the
     * act of looking at the panel. The push keeps its own throttle (a success younger than a
     * minute is not repeated) and its transport budget; whatever it answers is rendered from
     * that answer alone, and no answer renders as unknown.
     *
     * Only an existing key is transmitted: the render must never mint one (the unattended push
     * would, on a keyless installation), that stays with the authorization flow and the CLI.
     */
    protected function forceSigningKeyPush(): ?AgentSigningKeyPushResult
    {
        try {
            if (!$this->agentKeyPairService->hasKeyPair()) {
                return null;
            }

            return $this->agentSigningKeyPushService->pushIfNecessary(true);
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'NEOSidekick settings module could not push the signing key: ' . $throwable->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return null;
        }
    }

    /**
     * Read-only view of the agent signing keypair for the administrator's panel: the
     * fingerprint identifying this installation's key, the status NEOSidekick last reported
     * for it, and whether a regeneration is still waiting for NEOSidekick's confirmation
     * (driven by the pending pair in the signing-key row, not by any recorded outcome).
     *
     * Deliberately never generates a keypair: RSA generation belongs in the CLI, the regenerate
     * action or the authorization flow, not in a backend module render. Every question to the
     * keypair service sits inside the try, so a missing, unusable or unreadable keypair is
     * reported as a state instead of a 500 that would hide the panel.
     *
     * The host status comes from the push made on this render ({@see forceSigningKeyPush}) and
     * from nothing recorded: `installAddress` is the label NEOSidekick calls this installation
     * at, `registeredHosts` the host set it stored, `thisHostRegistered` whether the host this
     * module was opened on is in that set, and `hostsResult` what NEOSidekick did with the
     * pushed set. `hostStatusKnown` is false when the push was skipped or failed, or when an
     * older NEOSidekick echoed no host set; the panel then says "unknown" and offers neither the
     * copy nor the moved-site answer.
     *
     * @param AgentSigningKeyPushResult|null $forcedPushResult The answer of the push made on this render, null when it was skipped
     * @return array{exists: bool, fingerprint: string, status: string, pushedAt: string, regenerateIncomplete: bool, relabelPending: bool, hostStatusKnown: bool, installAddress: string|null, registeredHosts: array<int, string>, thisHost: string|null, thisHostRegistered: bool, hostsResult: string|null}
     */
    protected function getSigningKeyDetails(?AgentSigningKeyPushResult $forcedPushResult = null): array
    {
        $pushStatus = $this->agentSigningKeyPushService->getPushStatus();
        $details = array_merge([
            'exists' => false,
            'fingerprint' => '',
            'status' => 'none',
            'pushedAt' => '',
            'regenerateIncomplete' => false,
            'relabelPending' => false,
        ], $this->hostStatusDetails($forcedPushResult));

        try {
            $details['regenerateIncomplete'] = $this->agentKeyPairService->hasPendingKeyPair();
            $details['relabelPending'] = $this->agentKeyPairService->isPendingRelabel();
            if (!$this->agentKeyPairService->hasKeyPair()) {
                return $details;
            }
            if (!$this->agentKeyPairService->isLiveKeyPairUsable()) {
                return array_merge($details, ['exists' => true, 'status' => 'unusable']);
            }

            return array_merge($details, [
                'exists' => true,
                'fingerprint' => $this->agentKeyPairService->getFingerprint(),
                'status' => $pushStatus['status'],
                'pushedAt' => $pushStatus['pushedAt'],
            ]);
        } catch (RuntimeException $exception) {
            return $details;
        }
    }

    /**
     * The host half of the panel, from the answer of the push made on this render. "This host"
     * is the host the module was opened on - the request's, not the configured base URI's, so a
     * headless installation's editors see the answer for the host they are on - and it is
     * registered when NEOSidekick's stored set names it, compared the way NEOSidekick compares
     * hosts ({@see hostOf}).
     *
     * @return array{hostStatusKnown: bool, installAddress: string|null, registeredHosts: array<int, string>, thisHost: string|null, thisHostRegistered: bool, hostsResult: string|null}
     */
    protected function hostStatusDetails(?AgentSigningKeyPushResult $forcedPushResult): array
    {
        $thisHost = $this->currentRequestHost();
        $thisHost = $thisHost === null ? null : strtolower($thisHost);
        $known = $forcedPushResult !== null && $forcedPushResult->successful && $forcedPushResult->hosts !== null;
        if (!$known) {
            return [
                'hostStatusKnown' => false,
                'installAddress' => null,
                'registeredHosts' => [],
                'thisHost' => $thisHost,
                'thisHostRegistered' => false,
                'hostsResult' => null,
            ];
        }

        $registeredHosts = array_values(array_filter(
            array_map(static fn (string $origin): string => trim($origin), (array)$forcedPushResult->hosts),
            static fn (string $origin): bool => $origin !== ''
        ));
        $registeredHostNames = array_filter(array_map(fn (string $origin): ?string => $this->hostOf($origin), $registeredHosts));

        return [
            'hostStatusKnown' => true,
            'installAddress' => $forcedPushResult->registeredDomain,
            'registeredHosts' => $registeredHosts,
            'thisHost' => $thisHost,
            'thisHostRegistered' => $thisHost !== null && in_array($thisHost, $registeredHostNames, true),
            'hostsResult' => $forcedPushResult->hostsResult,
        ];
    }

    /**
     * The host this module request came in on; null outside an HTTP request.
     */
    protected function currentRequestHost(): ?string
    {
        try {
            $host = $this->request->getHttpRequest()->getUri()->getHost();
        } catch (Throwable $throwable) {
            return null;
        }

        return $host === '' ? null : $host;
    }

    /**
     * The host of a domain the way NEOSidekick derives it (NeosSiteUrl::hostOf()): lowercased,
     * with scheme, port and path dropped and `www` NOT folded away - a `www` switch is a moved
     * site there, not the same host. A bare host is read as one, because the stored labels come
     * both ways. Null when no host can be parsed, so the caller can tell "no answer" from
     * "another host".
     */
    protected function hostOf(?string $domain): ?string
    {
        if ($domain === null || trim($domain) === '') {
            return null;
        }

        $candidate = trim($domain);
        if (!str_contains($candidate, '://')) {
            $candidate = 'https://' . $candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    /**
     * Derives the current site's base URL as `scheme://host`, URL-encoded, so it can be
     * passed to the settings iframe the same way the chat iframes receive their `domain`
     * parameter. Reuses NEOSidekickInternalHelper::domain(), which resolves the active
     * request's domain first and falls back to the current HTTP request's scheme and host.
     * Returns an empty string when no host can be determined.
     *
     * @return string
     */
    protected function getSiteDomain(): string
    {
        $domain = $this->neosidekickInternalHelper->domain();
        if (empty(parse_url($domain, PHP_URL_HOST))) {
            return '';
        }
        return rawurlencode($domain);
    }
}

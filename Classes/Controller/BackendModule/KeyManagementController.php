<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use RuntimeException;
use Throwable;

/**
 * The key management module: what is wrong with this installation's connection to NEOSidekick,
 * and the one action that answers it. Opening the page pushes the key past the push service's
 * throttle, because the page exists to get a fresh verdict.
 *
 * Administrators only via NEOSidekick.AiAssistant:ManageKeys (Policy.yaml), which also gives
 * every action Flow's CSRF check.
 *
 * @noinspection PhpUnused
 */
class KeyManagementController extends AbstractFusionViewController
{
    /**
     * A failed key change, carried to the module's index action.
     */
    public const REGENERATION_FAILED_MESSAGE_CODE = 1756800010;

    /**
     * A succeeded - or unconfirmed - key change. Its own code so it is left in the container for
     * the Neos chrome instead of being consumed by the failure box.
     */
    public const REGENERATION_SUCCEEDED_MESSAGE_CODE = 1756800011;

    /**
     * A refused "we moved the site" confirmation. Its own code: it is not a failed key change
     * and renders next to the question rather than in the failure box.
     */
    public const RELABEL_REFUSED_MESSAGE_CODE = 1758300010;

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
     * @var Translator
     */
    protected $translator;

    /**
     * @param FusionView $view
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule/KeyManagement');
    }

    public function indexAction(): void
    {
        $this->denyBrowserCaching();
        $pushResult = $this->agentSigningKeyPushService->pushExistingKey(true);
        $flashMessages = $this->consumeKeyManagementFlashMessages();

        $this->view->assign('keyState', $this->deriveKeyState($pushResult));
        $this->view->assign('replaceKeyActionUri', $this->uriBuilder->reset()->uriFor('replaceKey'));
        $this->view->assign('reenrolActionUri', $this->uriBuilder->reset()->uriFor('reenrol'));
        $this->view->assign('relabelActionUri', $this->uriBuilder->reset()->uriFor('relabel'));
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
        $this->view->assign('sitesModuleUri', $this->getSitesModuleUri());
        $this->view->assign('regenerateFailure', $flashMessages['message']);
        $this->view->assign('regenerateRejectionReason', $flashMessages['reason']);
        $this->view->assign('relabelError', $flashMessages['relabelError']);
    }

    /**
     * The page is a verdict about right now, and the answers tell the administrator to come back
     * to it after changing something elsewhere. Served from the browser's back/forward cache it
     * would show the old verdict, so it is never stored. Neos' ModuleController merges these
     * headers of the module sub-response into the main response.
     */
    protected function denyBrowserCaching(): void
    {
        $this->response->setHttpHeader('Cache-Control', 'no-store, max-age=0');
        $this->response->setHttpHeader('Pragma', 'no-cache');
    }

    /**
     * POST-only: Flow's CSRF protection lets safe methods through, so a GET must not rotate.
     *
     * @throws StopActionException
     */
    protected function initializeReplaceKeyAction(): void
    {
        $this->refuseNonPostRequests();
    }

    /**
     * @throws StopActionException
     */
    protected function initializeReenrolAction(): void
    {
        $this->refuseNonPostRequests();
    }

    /**
     * @throws StopActionException
     */
    protected function initializeRelabelAction(): void
    {
        $this->refuseNonPostRequests();
    }

    /**
     * Replaces the key with a chained successor - the leak answer. Identity and connected tools
     * survive it. Refused while a key change is pending: the push would commit the waiting
     * successor, which may carry a relabel intent this button never asked for.
     */
    public function replaceKeyAction(): void
    {
        if ($this->refusedWhileAKeyChangeIsPending()) {
            $this->redirect('index');

            return;
        }

        $this->rotateSigningKey();
        $this->redirect('index');
    }

    /**
     * Gives this installation its own unchained key: the copy answer. A new lineage, so the
     * original keeps working and every tool connected here must be connected again.
     */
    public function reenrolAction(): void
    {
        $this->rotateSigningKey(true);
        $this->redirect('index');
    }

    /**
     * Moves the registration to the domain this installation answers as: the moved-site answer.
     * It disconnects the currently registered domain, hence the confirmation dialog on the form
     * and the refusal while a key change is pending.
     */
    public function relabelAction(): void
    {
        if ($this->refusedWhileAKeyChangeIsPending()) {
            $this->redirect('index');

            return;
        }

        $registeredDomain = $this->pushedRegisteredDomain();
        if ($registeredDomain === null || AgentInstallHostCollector::hostOf($registeredDomain) === null) {
            $this->addRelabelError(
                $this->translateRegenerationOutcome(
                    'signingKey.relabel.noRegisteredDomain',
                    [],
                    'NEOSidekick did not report a registered domain for this installation. Reload this page and try again.'
                )
            );
            $this->redirect('index');

            return;
        }

        $this->rotateSigningKey(false, true);
        $this->redirect('index');
    }

    /**
     * Changes this installation's signing key: the successor is transmitted chained to the
     * current key and replaces it once NEOSidekick registered it; a failed push changes nothing.
     * A key that cannot vouch for the successor aborts - re-enrolling orphans every connected
     * tool, so it needs its own button. The two flags are never sent together, and a relabel
     * NEOSidekick did not accept disarms itself so the unattended editor-authorize push cannot
     * carry it into a later chained retry.
     *
     * @param bool $reenrol Enrol this installation as a new one, unchained
     * @param bool $relabel Re-register this installation under the domain it answers as
     */
    protected function rotateSigningKey(bool $reenrol = false, bool $relabel = false): void
    {
        if (!$reenrol && $this->liveKeyPairIsUnusable()) {
            $this->addSigningKeyFailure(
                $this->translateRegenerationOutcome(
                    'signingKey.regenerate.unusableAbort',
                    [],
                    'The signing key was not regenerated: the stored key is unusable, so nothing can vouch for a successor. Restore the signing-key row from a backup to keep this installation\'s identity, or give this installation a new key.'
                )
            );

            return;
        }

        $result = $this->agentSigningKeyPushService->rotateKeyPair(null, $relabel, $reenrol);
        if ($result->successful && $result->isConfirmed()) {
            $this->addFlashMessage($this->successMessage($result, $relabel), '', Message::SEVERITY_OK, [], self::REGENERATION_SUCCEEDED_MESSAGE_CODE);

            return;
        }

        if ($result->successful) {
            $status = (string)$result->status;
            $message = $this->translateRegenerationOutcome(
                'signingKey.regenerate.unconfirmed',
                [$status],
                'The signing key was regenerated, but NEOSidekick reports it as ' . $status . '. Regenerate the key again to re-register this installation; connected tools will have to be reconnected.'
            );
            $this->addFlashMessage($message, '', Message::SEVERITY_WARNING, [], self::REGENERATION_SUCCEEDED_MESSAGE_CODE);

            return;
        }

        if ($relabel) {
            try {
                $this->agentKeyPairService->clearPendingRelabel();
            } catch (RuntimeException $exception) {
                // Left armed, but bounded by the retry back-off; the flash below still reports the failure.
            }
        }

        $this->addFlashMessage((string)$result->errorMessage, (string)$result->rejectionReason, Message::SEVERITY_ERROR, [], self::REGENERATION_FAILED_MESSAGE_CODE);
    }

    /**
     * What the push actually recorded, not what was asked for: a relabel whose chain key was
     * already revoked enrolled a new installation, and its tools must be reconnected.
     */
    protected function successMessage(AgentSigningKeyPushResult $result, bool $relabel): string
    {
        if (($this->agentSigningKeyPushService->getPushStatus()['status'] ?? '') === 'reenrolled') {
            return $this->translateRegenerationOutcome(
                'signingKey.status.reenrolled',
                [],
                'Registered as a new installation. Connected tools had to be reconnected with the new address.'
            );
        }

        if ($relabel) {
            return $this->translateRegenerationOutcome(
                'signingKey.relabel.success',
                [],
                'This installation is now registered under its current domain. Connected tools keep working.'
            );
        }

        return $this->translateRegenerationOutcome(
            'signingKey.regenerate.success',
            [],
            'The signing key was regenerated. This installation now identifies itself with the new key.'
        );
    }

    /**
     * A refusal the module decided on itself: no backend reason, same box as a backend rejection.
     */
    protected function addSigningKeyFailure(string $message): void
    {
        $this->addFlashMessage($message, '', Message::SEVERITY_ERROR, [], self::REGENERATION_FAILED_MESSAGE_CODE);
    }

    /**
     * Whether a stored keypair exists that cannot chain a successor. An unreadable row is not
     * this state: the rotation itself reports that failure.
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
     * The outcome shown to the administrator, translated here because the Neos chrome renders
     * flash messages untranslated; a missing unit falls back to the English source text.
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
            'BackendModule/KeyManagement',
            'NEOSidekick.AiAssistant'
        );

        return $translated ?? $englishFallback;
    }

    /**
     * @throws StopActionException
     */
    protected function refuseNonPostRequests(): void
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->throwStatus(405, null, 'The signing key is only changed on a POST from the module form.');
        }
    }

    /**
     * The one state the page renders, in remedy precedence: an unusable or revoked key needs a
     * new one whatever the host verdict says, an incomplete check says so, and only then does
     * the host verdict decide between the question block and the healthy line.
     *
     * @param AgentSigningKeyPushResult|null $pushResult The answer of the push made on this render, null when it was skipped or failed to run
     * @return array{state: string, variant: string|null, pendingChange: bool, registeredDomain: string|null, registeredHost: string|null, thisHost: string|null, registeredHosts: array<int, string>, fingerprint: string, installRootKid: string|null, rawResult: string|null, exists: bool}
     */
    protected function deriveKeyState(?AgentSigningKeyPushResult $pushResult): array
    {
        $keyFacts = $this->keyFacts();
        $registeredDomain = $pushResult?->registeredDomain ?? $this->recordedRegisteredDomain();
        [$state, $variant] = $this->stateAndVariant($keyFacts, $pushResult);

        return [
            'state' => $state,
            'variant' => $variant,
            'pendingChange' => $state !== 'needs_new_key' && $keyFacts['pending'],
            'registeredDomain' => $registeredDomain,
            'registeredHost' => $registeredDomain === null ? null : AgentInstallHostCollector::hostOf($registeredDomain),
            'thisHost' => $this->lowercasedRequestHost(),
            'registeredHosts' => $this->echoedHosts($pushResult),
            'fingerprint' => $keyFacts['fingerprint'],
            'installRootKid' => $this->recordedInstallRootKid(),
            'rawResult' => $this->rawResult($pushResult),
            'exists' => $keyFacts['exists'],
        ];
    }

    /**
     * @param array{exists: bool, usable: bool, pending: bool, fingerprint: string, status: string} $keyFacts
     * @return array{0: string, 1: string|null}
     */
    protected function stateAndVariant(array $keyFacts, ?AgentSigningKeyPushResult $pushResult): array
    {
        if (!$keyFacts['exists'] || !$keyFacts['usable']) {
            return ['needs_new_key', 'missing'];
        }
        if ($keyFacts['status'] === 'revoked') {
            return ['needs_new_key', 'revoked'];
        }
        if ($pushResult === null || !$pushResult->successful || $pushResult->hosts === null) {
            return ['check_failed', 'generic'];
        }

        return match ($pushResult->hostsResult) {
            'accepted' => ['healthy', null],
            'base_host_unknown' => ['host_unknown', null],
            'expired' => ['check_failed', 'expired'],
            default => ['check_failed', 'generic'],
        };
    }

    /**
     * Facts about the stored keypair. Every question sits inside one try, so a missing, unusable
     * or unreadable row is a state the page renders rather than a 500 that would hide it.
     *
     * @return array{exists: bool, usable: bool, pending: bool, fingerprint: string, status: string}
     */
    protected function keyFacts(): array
    {
        try {
            $pending = $this->agentKeyPairService->hasPendingKeyPair();
            if (!$this->agentKeyPairService->hasKeyPair()) {
                return ['exists' => false, 'usable' => false, 'pending' => $pending, 'fingerprint' => '', 'status' => 'none'];
            }
            if (!$this->agentKeyPairService->isLiveKeyPairUsable()) {
                return ['exists' => true, 'usable' => false, 'pending' => $pending, 'fingerprint' => '', 'status' => 'unusable'];
            }

            return [
                'exists' => true,
                'usable' => true,
                'pending' => $pending,
                'fingerprint' => $this->agentKeyPairService->getFingerprint(),
                'status' => (string)($this->agentSigningKeyPushService->getPushStatus()['status'] ?? 'none'),
            ];
        } catch (Throwable $throwable) {
            return ['exists' => false, 'usable' => false, 'pending' => false, 'fingerprint' => '', 'status' => 'none'];
        }
    }

    protected function hasPendingKeyPair(): bool
    {
        try {
            return $this->agentKeyPairService->hasPendingKeyPair();
        } catch (Throwable $throwable) {
            return false;
        }
    }

    /**
     * The host set NEOSidekick echoed on this render, trimmed; empty when it echoed none.
     *
     * @return array<int, string>
     */
    protected function echoedHosts(?AgentSigningKeyPushResult $pushResult): array
    {
        if ($pushResult === null || !$pushResult->successful || $pushResult->hosts === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $origin): string => trim($origin), $pushResult->hosts),
            static fn (string $origin): bool => $origin !== ''
        ));
    }

    /**
     * What NEOSidekick did with the pushed host set, or why the push did not get that far.
     * Technical details only; nothing branches on it.
     */
    protected function rawResult(?AgentSigningKeyPushResult $pushResult): ?string
    {
        if ($pushResult === null) {
            return null;
        }
        if (!$pushResult->successful) {
            return $pushResult->rejectionReason ?? $pushResult->errorMessage;
        }

        return $pushResult->hostsResult;
    }

    protected function recordedRegisteredDomain(): ?string
    {
        try {
            return $this->agentKeyPairService->getRecord()?->getPushRegisteredDomain();
        } catch (Throwable $throwable) {
            return null;
        }
    }

    protected function recordedInstallRootKid(): ?string
    {
        try {
            return $this->agentKeyPairService->getRecord()?->getPushInstallRootKid();
        } catch (Throwable $throwable) {
            return null;
        }
    }

    /**
     * The domain NEOSidekick reports right now. Read from a push made for this decision, never
     * from the record, because the relabel disconnects it.
     */
    protected function pushedRegisteredDomain(): ?string
    {
        $result = $this->agentSigningKeyPushService->pushExistingKey(true);

        return $result !== null && $result->successful ? $result->registeredDomain : null;
    }

    protected function lowercasedRequestHost(): ?string
    {
        $host = $this->currentRequestHost();

        return $host === null ? null : strtolower($host);
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
     * Where answer 1 sends the administrator to add the missing Domain record.
     */
    protected function getSitesModuleUri(): string
    {
        return $this->neosBackendModuleUri('administration/sites');
    }

    /**
     * Whether a key change is still waiting for NEOSidekick - any push would then commit that
     * pending pair (and a relabel intent it may carry) as a side effect. The page retries it on
     * every load, so the answer is to wait.
     */
    protected function refusedWhileAKeyChangeIsPending(): bool
    {
        if (!$this->hasPendingKeyPair()) {
            return false;
        }

        $this->addRelabelError(
            $this->translateRegenerationOutcome(
                'signingKey.relabel.pending',
                [],
                'A key change is still waiting for NEOSidekick. Reload this page until it is finished, then try again.'
            )
        );

        return true;
    }

    protected function addRelabelError(string $message): void
    {
        $this->addFlashMessage($message, '', Message::SEVERITY_ERROR, [], self::RELABEL_REFUSED_MESSAGE_CODE);
    }

    /**
     * The failures of the action this render follows. Read raw (not rendered): the text is the
     * backend's verbatim answer and may contain anything, including '%'. The rejection reason
     * travels as the message title. Messages of other origins stay in the container.
     *
     * @return array{message: string|null, reason: string|null, relabelError: string|null}
     */
    protected function consumeKeyManagementFlashMessages(): array
    {
        $consumed = ['message' => null, 'reason' => null, 'relabelError' => null];
        $flashMessageContainer = $this->controllerContext->getFlashMessageContainer();
        foreach ($flashMessageContainer->getMessagesAndFlush() as $message) {
            if ($message->getCode() === self::REGENERATION_FAILED_MESSAGE_CODE) {
                $consumed['message'] = $message->getMessage();
                $consumed['reason'] = $message->getTitle() === '' ? null : $message->getTitle();
                continue;
            }
            if ($message->getCode() === self::RELABEL_REFUSED_MESSAGE_CODE) {
                $consumed['relabelError'] = $message->getMessage();
                continue;
            }

            $flashMessageContainer->addMessage($message);
        }

        return $consumed;
    }
}

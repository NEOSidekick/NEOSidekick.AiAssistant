<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Error\Messages\Message;
use Neos\Flow\I18n\Locale;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use RuntimeException;

/**
 * One signing key rotation and its outcome as a flash message. A trait rather than a service
 * because every step is controller business (flash messages, interface language, redirect) while
 * the rotation itself lives in {@see AgentSigningKeyPushService}. Using controllers must declare
 * the collaborators it reads, so Flow's property injection sees them on the class.
 */
trait SigningKeyRotationTrait
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
            'BackendModule/Configuration',
            'NEOSidekick.AiAssistant'
        );

        return $translated ?? $englishFallback;
    }

    /**
     * The host of a domain the way NEOSidekick derives it (NeosSiteUrl::hostOf()): lowercased,
     * scheme/port/path dropped, `www` NOT folded away - a `www` switch is a moved site there.
     * Null when no host can be parsed, so callers can tell "no answer" from "another host".
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
}

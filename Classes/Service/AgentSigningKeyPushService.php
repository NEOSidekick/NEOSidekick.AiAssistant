<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Pushes this installation's public signing key to the NEOSidekick backend.
 *
 * An unchained push enrolls a new installation; a push carrying a chain signature
 * ({@see signRotationChain}) rotates an existing one's key. A regenerated key waits as a pending
 * pair and is promoted only once the backend registered it, so a lost answer converges instead of
 * starting a new lineage. Every failure path is caught, logged and reported through
 * {@see AgentSigningKeyPushResult} so the caller is never broken.
 *
 * @Flow\Scope("singleton")
 */
class AgentSigningKeyPushService
{
    private const PUSH_ENDPOINT_PATH = '/api/agentic-chat/signing-key';

    /**
     * A backend-side revocation is only learned by pushing again, so a confirmed or revoked key
     * is re-pushed once its record ages past this.
     */
    private const CONFIRMED_PUSH_MAX_AGE_SECONDS = 86400;

    /**
     * Minimum age of the recorded attempt before the authorization flow re-pushes an unconfirmed
     * regeneration. A rotation (button, CLI) never waits.
     */
    private const PENDING_RETRY_SECONDS = 60;

    /**
     * A forced push (the assistant's "Try again") skips the confirmed-and-fresh short-circuit, but
     * not while the last successful push is younger than this: a click storm must not become a
     * push storm. A failed push records nothing and is bounded by the handshake's own pacing.
     */
    private const FORCED_PUSH_MIN_INTERVAL_SECONDS = 60;

    /**
     * The backend's refusal of a chained push whose chain it cannot verify: the key the pending
     * key is chained to is unknown or revoked there. {@see pushPendingKeyPair} recovers from it.
     */
    private const REJECTION_REASON_CHAIN_UNVERIFIABLE = 'chain_unverifiable';

    /**
     * The backend caps `plugin_version` at 32 characters and fails the whole push above it.
     */
    private const MAX_PLUGIN_VERSION_LENGTH = 32;

    /**
     * Why a regeneration on an unusable live pair stops instead of enrolling this installation
     * anew: the unchained push would start a new lineage and orphan every connected tool, while
     * the row is still restorable from a backup.
     */
    public const UNUSABLE_KEYPAIR_ABORT_MESSAGE = 'cannot chain the pending key: the stored keypair is unusable. Restore the signing-key row from a backup to keep this installation\'s identity, or re-enrol it explicitly.';

    /**
     * The push runs inside the editor's authorization request, whose 8 s client-side fallback must
     * also cover the callback that follows. A push exceeding the budget is retried on the next
     * authorization.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 2;

    /**
     * An explicit regeneration is not on the authorization budget, so it waits considerably longer.
     */
    private const ROTATION_TIMEOUT_SECONDS = 15;

    /**
     * @Flow\Inject
     * @var AgentKeyPairService
     */
    protected AgentKeyPairService $agentKeyPairService;

    /**
     * Used for the WRITE only ({@see recordPush}); every read goes through the keypair service,
     * which holds the one managed instance of the row.
     *
     * @Flow\Inject
     * @var AgentSigningKeyRecordRepository
     */
    protected $agentSigningKeyRecordRepository;

    /**
     * @Flow\Inject
     * @var NEOSidekickInternalHelper
     */
    protected $neosidekickInternalHelper;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey = '';

    /**
     * @Flow\InjectConfiguration(path="agent.externalApiDomain")
     * @var string|null
     */
    protected ?string $externalApiDomain = null;

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REVOKED = 'revoked';

    /**
     * Not a backend status: reported by {@see getPushStatus} when a rotation's confirmation carried
     * a different `install_root_kid`, i.e. the backend enrolled this installation anew.
     */
    public const STATUS_REENROLLED = 'reenrolled';

    /**
     * The fire-and-forget entry point for the authorization flow; returns null when there is
     * nothing to do. It also mints the first keypair of a keyless installation, re-pushes an
     * unconfirmed key and refreshes a confirmed or revoked one once its record is stale.
     *
     * Generation happens only after the target check, so an installation without an API domain
     * never mints an inert identity.
     *
     * @param bool $force Push a confirmed or revoked key even while its record is fresh - the
     *                    assistant's "Try again" after NEOSidekick rejected a token - unless the
     *                    last successful push is younger than {@see FORCED_PUSH_MIN_INTERVAL_SECONDS}.
     *                    A hint, not an instruction: the payload is built from local state alone,
     *                    and a pending pair is retried on its own schedule regardless.
     *
     * Unattended, so a pending pair whose chain the backend refuses is not re-sent in the same
     * call ({@see pushPendingKeyPair}); it chains on its next due retry.
     */
    public function pushIfNecessary(bool $force = false): ?AgentSigningKeyPushResult
    {
        try {
            $target = $this->getTarget();
            if ($target === null) {
                return null;
            }

            if (!$this->agentKeyPairService->hasKeyPair()) {
                if ($this->agentKeyPairService->isStorageMissingTable()) {
                    return null;
                }
                $this->agentKeyPairService->generateKeyPair();
            }

            if ($this->agentKeyPairService->hasPendingKeyPair()) {
                if (!$this->pendingRetryIsDue()) {
                    return null;
                }
                $this->agentKeyPairService->markPendingKeyPairRetried();
            } else {
                $recordedStatus = $this->getRecordedStatusOfCurrentKey();
                $settled = $recordedStatus === self::STATUS_CONFIRMED || $recordedStatus === self::STATUS_REVOKED;
                if (
                    $settled
                    && !$this->recordedPushIsStale()
                    && (!$force || time() - (int)$this->recordedPush()?->getPushedAt()?->getTimestamp() < self::FORCED_PUSH_MIN_INTERVAL_SECONDS)
                ) {
                    return null;
                }
            }
        } catch (Throwable $throwable) {
            $this->logPushFailure('could not be prepared: ' . $throwable->getMessage());

            return null;
        }

        return $this->pushCurrentKey(null, null, null, self::DEFAULT_TIMEOUT_SECONDS, false);
    }

    /**
     * A pending pair without a retry stamp is due: pushing once too often is free.
     */
    private function pendingRetryIsDue(): bool
    {
        $retriedAt = $this->agentKeyPairService->getPendingKeyPairRetriedAt();

        return $retriedAt === null || (time() - $retriedAt) >= self::PENDING_RETRY_SECONDS;
    }

    /**
     * The plugin-side gate: only a key the backend has enrolled mints new-generation tokens.
     *
     * Never throws - anything unreadable counts as not confirmed.
     */
    public function isKeyConfirmed(): bool
    {
        try {
            return $this->getRecordedStatusOfCurrentKey() === self::STATUS_CONFIRMED;
        } catch (Throwable $throwable) {
            return false;
        }
    }

    /**
     * Creates the successor as a pending pair (or reuses the one still waiting), pushes it chained
     * to the live key and promotes it once the backend registered it. Without a keypair to chain
     * to, a fresh pair becomes the live pair and is enrolled unchained.
     *
     * The live pair stays in use until the promotion, and both the pending write and the commit
     * are compare-and-swaps, so concurrent regenerations reuse the same successor.
     *
     * The pair is stamped as transmitted before the push goes out, so the automatic
     * editor-authorize retry ({@see pushIfNecessary}, {@see PENDING_RETRY_SECONDS} back-off)
     * cannot pick up a freshly minted pair while this push is still in flight. That pair may
     * carry a relabel intent, which no unattended retry may announce.
     *
     * @param string|null $domainOverride For callers with no resolvable site domain
     * @param bool $relabel Re-register the installation under the pushed domain instead of the
     *                      one the chain key is registered for (sticky for the pending key)
     * @param bool $allowReenrolment Announce the successor unchained, which enrols this
     *                               installation anew and orphans every connected tool. It means
     *                               "do not chain", not "chain if you can": a copy's live key is
     *                               perfectly readable, and a chained announcement from it is
     *                               refused by the backend's clone rule. Both this and $relabel
     *                               mint a fresh successor rather than reuse a waiting one.
     */
    public function rotateKeyPair(?string $domainOverride = null, bool $relabel = false, bool $allowReenrolment = false): AgentSigningKeyPushResult
    {
        try {
            if (!$this->agentKeyPairService->hasKeyPair()) {
                if ($this->agentKeyPairService->isStorageMissingTable()) {
                    throw new RuntimeException(
                        'the signing-key table does not exist yet - the database migration has not run (./flow doctrine:migrate).',
                        1757000023
                    );
                }
                $this->agentKeyPairService->generateKeyPair();

                return $this->pushLiveKeyPair(null, null, $domainOverride, self::ROTATION_TIMEOUT_SECONDS);
            }

            if (!$allowReenrolment && !$this->agentKeyPairService->isLiveKeyPairUsable()) {
                throw new RuntimeException(self::UNUSABLE_KEYPAIR_ABORT_MESSAGE, 1757000024);
            }

            $this->agentKeyPairService->preparePendingKeyPair($relabel, $allowReenrolment || $relabel);
            $this->agentKeyPairService->markPendingKeyPairRetried();

            return $this->pushPendingKeyPair($domainOverride, self::ROTATION_TIMEOUT_SECONDS, $allowReenrolment, true);
        } catch (Throwable $throwable) {
            $this->logPushFailure('could not regenerate the keypair: ' . $throwable->getMessage());

            return AgentSigningKeyPushResult::failure('The signing key could not be regenerated: ' . $throwable->getMessage());
        }
    }

    /**
     * The operator's explicit push (`agentkey:push`). While a pending pair exists the given chain
     * material is ignored: the pending key is transmitted, freshly chained to the live key, and
     * re-sent once should the backend first have to learn the live key ({@see pushPendingKeyPair}).
     *
     * @param string|null $chainKid The kid of the previous (currently-confirmed) key
     * @param string|null $chainSignature See {@see signRotationChain}
     * @param string|null $domainOverride For callers with no resolvable site domain
     */
    public function push(?string $chainKid = null, ?string $chainSignature = null, ?string $domainOverride = null): AgentSigningKeyPushResult
    {
        return $this->pushCurrentKey($chainKid, $chainSignature, $domainOverride, self::ROTATION_TIMEOUT_SECONDS, true);
    }

    /**
     * @param int $pendingTimeoutSeconds The timeout for a pending key's re-push; the live key is
     *                                   always pushed on the authorization budget
     * @param bool $resendPendingAfterRecovery See {@see pushPendingKeyPair}
     */
    private function pushCurrentKey(?string $chainKid, ?string $chainSignature, ?string $domainOverride, int $pendingTimeoutSeconds, bool $resendPendingAfterRecovery): AgentSigningKeyPushResult
    {
        try {
            if (!$this->agentKeyPairService->hasKeyPair()) {
                return AgentSigningKeyPushResult::failure('No agent signing keypair exists yet.');
            }

            if ($this->agentKeyPairService->hasPendingKeyPair()) {
                return $this->pushPendingKeyPair($domainOverride, $pendingTimeoutSeconds, false, $resendPendingAfterRecovery);
            }

            return $this->pushLiveKeyPair($chainKid, $chainSignature, $domainOverride, self::DEFAULT_TIMEOUT_SECONDS);
        } catch (Throwable $throwable) {
            $this->logPushFailure('failed: ' . $throwable->getMessage());

            return AgentSigningKeyPushResult::failure('The signing key could not be pushed to NEOSidekick: ' . $throwable->getMessage());
        }
    }

    /**
     * Transmits the live key and records the answer.
     *
     * A backend that does not know the key enrols it as a new installation. When a push at the
     * same target had echoed another lineage root before, that is a re-enrolment and is flagged
     * exactly like a rotation's ({@see STATUS_REENROLLED}); otherwise the previous flag is carried
     * forward. After a target switch the previous root is unknown and nothing is flagged.
     *
     * @throws Throwable Anything the caller's catch-all turns into a failure result
     */
    private function pushLiveKeyPair(?string $chainKid, ?string $chainSignature, ?string $domainOverride, int $timeoutSeconds): AgentSigningKeyPushResult
    {
        $previousInstallRootKid = $this->getRecordedInstallRootKid();
        $result = $this->transmit($this->agentKeyPairService->getPublicKeyPem(), $chainKid, $chainSignature, $domainOverride, false, $timeoutSeconds);
        if ($result->successful) {
            $reenrolled = $this->installRootKidChanged($previousInstallRootKid, $result->installRootKid) ? true : null;
            $this->recordPush($this->agentKeyPairService->getKeyId(), (string)$this->getTarget(), (string)$result->status, $result->installRootKid, $reenrolled, $result->registeredDomain);
        }

        return $result;
    }

    /**
     * Transmits the pending key chained to the live key and promotes it once the backend
     * registered it.
     *
     * The commit names the key the backend confirmed and its failure is deliberately not caught:
     * a pending pair replaced by a concurrent regeneration must never be reported as confirmed.
     *
     * A chained push the backend refuses as `chain_unverifiable` (it never learned, or has
     * revoked, the live key) is recovered from by announcing the live key unchained once -
     * byte-for-byte what {@see pushIfNecessary} sends while no pending pair exists. A backend
     * that knows the key answers with its stored status and changes nothing; one that does not
     * enrols it, which {@see pushLiveKeyPair} flags as a re-enrolment when the same target had
     * echoed another root. The announcement records its own answer, so a revoked lineage ends
     * with a persistent `revoked` in the module, where re-enrolment is offered as the way out.
     * Then, at most once per push:
     *  - on an operator path ($resendPendingAfterRecovery) with the live key confirmed, the
     *    pending key is chained to it once more and, when accepted, promoted and recorded
     *    against the root captured BEFORE the announcement, so the flag written there survives;
     *  - otherwise the ORIGINAL refusal is returned and the pending pair stays: unattended, it
     *    chains on its next due retry, which now verifies.
     * `chain_domain_mismatch` is the clone guard and stays a hard stop; a transport failure
     * carries no reason and triggers nothing.
     *
     * @param bool $allowReenrolment Announce the pending key unchained - whether or not the live
     *                               pair could vouch for it - which enrols this installation anew.
     *                               The chain is computed either way, so a storage failure while
     *                               chaining still aborts before any transmit. Only an
     *                               administrator who asked for it may set this.
     * @param bool $resendPendingAfterRecovery Re-send the pending key in the same call once the
     *                                         live key was announced (rotation, `agentkey:push`);
     *                                         never on the unattended path
     * @throws Throwable Anything the caller's catch-all turns into a failure result
     */
    private function pushPendingKeyPair(?string $domainOverride, int $timeoutSeconds, bool $allowReenrolment, bool $resendPendingAfterRecovery): AgentSigningKeyPushResult
    {
        if (!$this->agentKeyPairService->hasPendingKeyPair()) {
            return $this->pushLiveKeyPair(null, null, $domainOverride, $timeoutSeconds);
        }

        $target = (string)$this->getTarget();
        $pendingPublicKeyPem = $this->agentKeyPairService->getPendingPublicKeyPem();
        $pendingKeyId = AgentKeyPairService::deriveKeyId($pendingPublicKeyPem);
        $relabel = $this->agentKeyPairService->isPendingRelabel();
        $chainKid = null;
        $chainSignature = null;
        try {
            $chainKid = $this->agentKeyPairService->getKeyId();
            $chainSignature = self::signRotationChain($pendingPublicKeyPem, $this->agentKeyPairService->getPrivateKeyPem());
        } catch (AgentSigningKeyStorageException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $chainSignature = null;
        }
        if ($chainSignature === null) {
            if (!$allowReenrolment) {
                throw new RuntimeException(self::UNUSABLE_KEYPAIR_ABORT_MESSAGE, 1757000024);
            }
            $this->logPushFailure('cannot chain the pending key: the live keypair is unusable, so the pending key is announced unchained and enrolls a new installation', $chainKid);
            $chainKid = null;
        } elseif ($allowReenrolment) {
            $this->logPushFailure('was not chained: re-enrolment requested by the administrator, announced unchained, so the pending key enrolls a new installation', $chainKid);
            $chainKid = null;
            $chainSignature = null;
        }

        $previousInstallRootKid = $this->getRecordedInstallRootKid();
        $result = $this->transmit($pendingPublicKeyPem, $chainKid, $chainSignature, $domainOverride, $relabel, $timeoutSeconds);
        if ($chainKid !== null && !$result->successful && $result->rejectionReason === self::REJECTION_REASON_CHAIN_UNVERIFIABLE) {
            $this->logPushFailure(
                'was refused as chain_unverifiable: announcing the live key unchained'
                . ($resendPendingAfterRecovery ? ' before chaining the pending key to it once more' : '; the pending key chains on its next retry'),
                $chainKid
            );
            $liveResult = $this->pushLiveKeyPair(null, null, $domainOverride, $timeoutSeconds);
            if (!$resendPendingAfterRecovery || !$liveResult->isConfirmed()) {
                return $result;
            }
            $result = $this->transmit($pendingPublicKeyPem, $chainKid, $chainSignature, $domainOverride, $relabel, $timeoutSeconds);
        }
        if (!$result->successful) {
            return $result;
        }

        if (!$this->agentKeyPairService->commitPendingKeyPair($pendingKeyId)) {
            return $result;
        }

        $reenrolled = $this->installRootKidChanged($previousInstallRootKid, $result->installRootKid);
        $this->recordPush($pendingKeyId, $target, (string)$result->status, $result->installRootKid, $reenrolled, $result->registeredDomain);

        return $result;
    }

    /**
     * A lineage change is only ever detected between two roots the same target echoed: an
     * unknown previous root (first push, target switch, a record predating the field) never
     * reads as changed.
     */
    private function installRootKidChanged(?string $previousInstallRootKid, ?string $echoedInstallRootKid): bool
    {
        return $previousInstallRootKid !== null
            && $echoedInstallRootKid !== null
            && $previousInstallRootKid !== $echoedInstallRootKid;
    }

    /**
     * The wire exchange only: builds the payload, sends it and reads the answer. Recording the
     * state is the caller's business because a pending key must be promoted first.
     *
     * @throws Throwable On transport failure; the caller's catch-all reports it
     */
    private function transmit(string $publicKeyPem, ?string $chainKid, ?string $chainSignature, ?string $domainOverride, bool $relabel, int $timeoutSeconds): AgentSigningKeyPushResult
    {
        $target = $this->getTarget();
        if ($target === null) {
            return AgentSigningKeyPushResult::failure('No NEOSidekick API domain is configured (agent.externalApiDomain).');
        }

        $domain = $this->resolveDomain($domainOverride);
        if ($domain === null) {
            $this->logPushFailure('was refused: this site\'s domain could not be determined');

            return AgentSigningKeyPushResult::failure(
                'The signing key was not pushed because this installation\'s domain could not be determined. '
                . 'The pushed domain becomes the key\'s label in NEOSidekick and is used to decide where token renewals may be sent, '
                . 'so a guessed value (e.g. "http://localhost") must never be registered. '
                . 'Configure Neos.Flow.http.baseUri (or set up a Neos domain record for the site), or pass the domain explicitly with '
                . '<b>./flow agentkey:push --domain https://www.example.com</b>.'
            );
        }

        $publicKeyPem = trim($publicKeyPem);
        $keyId = AgentKeyPairService::deriveKeyId($publicKeyPem);

        $payload = [
            'public_key_pem' => $publicKeyPem,
            'kid' => $keyId,
            'domain' => $domain,
            'plugin_version' => $this->getPluginVersion(),
            'chain_kid' => $chainKid,
            'chain_signature' => $chainSignature,
            'relabel' => $relabel,
        ];

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        // http_errors=false: the 4xx/5xx body carries the only diagnosable rejection reason.
        $response = $this->createPushClient($timeoutSeconds)->post($target . self::PUSH_ENDPOINT_PATH, [
            'json' => $payload,
            'headers' => $headers,
            'http_errors' => false,
        ]);

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();

        if ($statusCode !== 200) {
            $this->logPushFailure(sprintf('was rejected with status %d: %s', $statusCode, $this->capForLog($body)), $keyId);

            return AgentSigningKeyPushResult::failure(
                sprintf('The NEOSidekick backend rejected the signing key with status %d: %s', $statusCode, $this->extractErrorText($body)),
                $keyId,
                $this->extractRejectionReason($body)
            );
        }

        try {
            $decodedBody = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->logPushFailure('returned an unparsable response: ' . $exception->getMessage(), $keyId);

            return AgentSigningKeyPushResult::failure('The NEOSidekick backend returned an unparsable response to the signing key push.', $keyId);
        }
        if (!is_array($decodedBody) || !isset($decodedBody['status'])) {
            $this->logPushFailure('returned an unreadable response body: ' . $this->capForLog($body), $keyId);

            return AgentSigningKeyPushResult::failure('The NEOSidekick backend returned an unreadable response to the signing key push.', $keyId);
        }

        return AgentSigningKeyPushResult::success(
            (string)$decodedBody['status'],
            isset($decodedBody['kid']) ? (string)$decodedBody['kid'] : $keyId,
            isset($decodedBody['fingerprint']) ? (string)$decodedBody['fingerprint'] : null,
            isset($decodedBody['install_root_kid']) ? (string)$decodedBody['install_root_kid'] : null,
            isset($decodedBody['domain']) && is_string($decodedBody['domain']) && $decodedBody['domain'] !== '' ? $decodedBody['domain'] : null
        );
    }

    /**
     * Proves possession of the key the backend currently trusts - the difference between rotating
     * this installation's key and enrolling as a separate installation. The subject is signed
     * *trimmed*, the only form both sides agree on.
     *
     * @return string|null The base64 signature, or null when the previous key is unusable
     */
    public static function signRotationChain(string $newPublicKeyPem, string $previousPrivateKeyPem): ?string
    {
        $previousPrivateKey = openssl_pkey_get_private($previousPrivateKeyPem);
        if ($previousPrivateKey === false) {
            return null;
        }

        $signature = '';
        if (!openssl_sign(trim($newPublicKeyPem), $signature, $previousPrivateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return base64_encode($signature);
    }

    /**
     * The domain this installation would push, for readers outside the push itself (the module
     * panel compares it with the domain the backend has the lineage registered under). Null when
     * it cannot be determined - the same answer that refuses a push.
     */
    public function getPushDomain(): ?string
    {
        return $this->resolveDomain(null);
    }

    /**
     * The pushed domain becomes the backend's renewal allowlist entry, so an undeterminable domain
     * returns null (refusing the push) rather than a guess.
     */
    protected function resolveDomain(?string $domainOverride): ?string
    {
        if ($domainOverride !== null && trim($domainOverride) !== '') {
            return rtrim(trim($domainOverride), '/');
        }

        return $this->neosidekickInternalHelper->resolveTrustedDomain();
    }

    /**
     * A missing timestamp counts as stale: never re-pushing is the failure mode this guards
     * against. The push is the only writer of the version the backend knows, so a recorded version
     * other than the current one is stale regardless of age. A false answer therefore guarantees a
     * recorded timestamp, which {@see pushIfNecessary} reads for its forced-push throttle.
     */
    protected function recordedPushIsStale(): bool
    {
        $record = $this->recordedPush();
        if ($record === null) {
            return true;
        }

        if ($record->getPushPluginVersion() !== $this->getPluginVersion()) {
            return true;
        }

        $pushedAt = $record->getPushedAt();
        if ($pushedAt === null) {
            return true;
        }

        return (time() - $pushedAt->getTimestamp()) >= self::CONFIRMED_PUSH_MAX_AGE_SECONDS;
    }

    /**
     * The recorded status of the current key at the current target, for the backend module; a
     * confirmed key whose rotation re-enrolled the installation reads as {@see STATUS_REENROLLED}.
     * Never throws - anything unreadable is reported as `none`.
     *
     * @return array{status: string, pushedAt: string, registeredDomain: string|null}
     */
    public function getPushStatus(): array
    {
        $none = ['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null];
        try {
            $record = $this->recordedPush();
            if (
                $record === null
                || $record->getPushKid() !== $this->agentKeyPairService->getKeyId()
                || $record->getPushTarget() !== $this->getTarget()
                || $record->getPushStatus() === null
            ) {
                return $none;
            }

            $status = $record->getPushStatus();
            if ($status === self::STATUS_CONFIRMED && $record->getPushReenrolled() === true) {
                $status = self::STATUS_REENROLLED;
            }

            return [
                'status' => $status,
                'pushedAt' => $record->getPushedAt()?->format(DATE_ATOM) ?? '',
                'registeredDomain' => $record->getPushRegisteredDomain(),
            ];
        } catch (Throwable $throwable) {
            return $none;
        }
    }

    /**
     * @throws Throwable When the keypair or the state is unreadable
     */
    private function getRecordedStatusOfCurrentKey(): ?string
    {
        $target = $this->getTarget();
        if ($target === null) {
            return null;
        }

        $record = $this->recordedPush();
        if (
            $record === null
            || $record->getPushKid() !== $this->agentKeyPairService->getKeyId()
            || $record->getPushTarget() !== $target
        ) {
            return null;
        }

        return $record->getPushStatus();
    }

    /**
     * Null when no push at the current target echoed one: unknown, which must never read as
     * "changed".
     */
    private function getRecordedInstallRootKid(): ?string
    {
        $record = $this->recordedPush();
        if ($record === null || $record->getPushTarget() !== $this->getTarget()) {
            return null;
        }

        return $record->getPushInstallRootKid();
    }

    /**
     * The row, but only when a push was ever recorded on it: `pushKid` is the column every push
     * writes, so a NULL there means the other push columns are unwritten as well. Read ONCE per
     * reader, so a refresh between two reads cannot mix two states.
     *
     * @throws Throwable When the row could not be loaded
     */
    private function recordedPush(): ?AgentSigningKeyRecord
    {
        $record = $this->agentKeyPairService->getRecord();
        if ($record === null || $record->getPushKid() === null) {
            return null;
        }

        return $record;
    }

    /**
     * Null when no API domain is configured - a development setup with nothing to push to.
     */
    public function getTarget(): ?string
    {
        if ($this->externalApiDomain === null || $this->externalApiDomain === '') {
            return null;
        }

        return rtrim($this->externalApiDomain, '/');
    }

    protected function createPushClient(int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): Client
    {
        return new Client([
            'connect_timeout' => $timeoutSeconds,
            'timeout' => $timeoutSeconds,
        ]);
    }

    /**
     * Diagnostic metadata; an install unknown to Composer is sent as null, not a blank string.
     */
    protected function getPluginVersion(): ?string
    {
        $pluginVersion = $this->neosidekickInternalHelper->pluginVersion();
        if ($pluginVersion === '') {
            return null;
        }

        return mb_substr($pluginVersion, 0, self::MAX_PLUGIN_VERSION_LENGTH);
    }

    /**
     * Writes the push state onto the signing-key row and refreshes it. A rotation decides
     * `reenrolled` itself; every other push carries the previous flag forward while the echoed
     * `install_root_kid` describes the same lineage at the same target.
     *
     * The flag is written as true or NULL, never false: NULL is the column's "never written" state.
     *
     * The registered domain is the opposite: it is the label the backend last echoed, so a null
     * OVERWRITES rather than carries forward - a stale label would silently hide a copy warning.
     *
     * @param bool|null $reenrolled Null carries the previous record's flag forward
     * @param string|null $registeredDomain The domain the backend echoed; null clears the column
     */
    protected function recordPush(string $keyId, string $target, string $status, ?string $installRootKid = null, ?bool $reenrolled = null, ?string $registeredDomain = null): void
    {
        try {
            $record = $this->agentKeyPairService->getRecord();
            if ($record === null) {
                throw new RuntimeException('There is no signing-key row to record the push on.', 1757000020);
            }

            $pushedAt = new DateTimeImmutable();
            $reenrolledAt = null;
            if ($reenrolled === null) {
                $sameLineage = $record->getPushKid() !== null
                    && $record->getPushTarget() === $target
                    && $installRootKid !== null
                    && $record->getPushInstallRootKid() === $installRootKid;
                $reenrolled = $sameLineage && $record->getPushReenrolled() === true;
                if ($reenrolled) {
                    $reenrolledAt = $record->getPushReenrolledAt() ?? $record->getPushedAt() ?? $pushedAt;
                }
            } elseif ($reenrolled) {
                $reenrolledAt = $pushedAt;
            }

            $this->agentSigningKeyRecordRepository->updateInstallRow([
                'pushKid' => $keyId,
                'pushTarget' => $target,
                'pushStatus' => $status,
                'pushPluginVersion' => $this->getPluginVersion(),
                'pushedAt' => $pushedAt,
                'pushInstallRootKid' => $installRootKid,
                'pushReenrolled' => $reenrolled ? true : null,
                'pushReenrolledAt' => $reenrolledAt,
                'pushRegisteredDomain' => $registeredDomain,
            ]);
            $this->agentKeyPairService->refreshAfterRowUpdate();
        } catch (Throwable $throwable) {
            // An unrecorded successful push costs one redundant push, never a failed authorization.
            $this->logPushFailure('succeeded but its state could not be recorded: ' . $throwable->getMessage(), $keyId);
        }
    }

    private function logPushFailure(string $message, ?string $keyId = null): void
    {
        $this->logger->warning(
            sprintf(
                'NEOSidekick agent signing key push %s%s',
                $message,
                $keyId !== null ? ' (kid ' . $keyId . ')' : ''
            ),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }

    /**
     * The backend's rejection body is JSON with `error`/`message` and a `reason`; anything else is
     * shown as-is, capped.
     */
    private function extractErrorText(string $body): string
    {
        $decoded = $this->decodeRejectionBody($body);
        if ($decoded === null) {
            return $this->capForLog($body);
        }

        $text = null;
        foreach (['message', 'error'] as $field) {
            if (isset($decoded[$field]) && is_string($decoded[$field]) && trim($decoded[$field]) !== '') {
                $text = trim($decoded[$field]);
                break;
            }
        }
        if ($text === null) {
            return $this->capForLog($body);
        }
        $reason = $this->extractRejectionReason($body);
        if ($reason !== null) {
            $text .= ' (' . $reason . ')';
        }

        return $this->capForLog($text);
    }

    /**
     * The machine-readable token of a rejection (e.g. `chain_domain_mismatch`), capped.
     */
    private function extractRejectionReason(string $body): ?string
    {
        $decoded = $this->decodeRejectionBody($body);
        if ($decoded === null || !isset($decoded['reason']) || !is_string($decoded['reason']) || trim($decoded['reason']) === '') {
            return null;
        }

        return $this->capForLog(trim($decoded['reason']));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeRejectionBody(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function capForLog(string $body): string
    {
        if (mb_strlen($body) <= 500) {
            return $body;
        }

        return mb_substr($body, 0, 500) . '…[truncated]';
    }
}

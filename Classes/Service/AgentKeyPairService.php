<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\Doctrine\Exception\DatabaseStructureException;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Manages this installation's RS256 agent signing keypair, held in the single
 * {@see AgentSigningKeyRecord} row. The private key is stored in plaintext by owner decision.
 * A missing signing-key table reads as "no key", while writes fail with a hint to run the
 * migration. Every method loads the row through {@see AgentSigningKeyRecordRepository}.
 *
 * @Flow\Scope("singleton")
 */
class AgentKeyPairService
{
    private const RSA_KEY_BITS = 2048;

    /**
     * @Flow\Inject
     * @var AgentSigningKeyRecordRepository
     */
    protected $agentSigningKeyRecordRepository;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * The row of this installation once loaded, only ever as a managed entity.
     */
    private ?AgentSigningKeyRecord $record = null;

    /**
     * Whether the last load found the signing-key table missing.
     */
    private bool $storageMissingTable = false;

    /**
     * Whether the missing-table warning was already logged in this request.
     */
    private bool $missingTableLogged = false;

    /**
     * Whether a live keypair exists. A missing signing-key table counts as "no key".
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded for any other reason
     */
    public function hasKeyPair(): bool
    {
        return $this->loadRecord() !== null;
    }

    /**
     * Whether the stored live pair is one usable keypair. Asked instead of {@see ensureKeyPair}
     * where an answer is wanted rather than a failure: never generates a pair and throws only the
     * storage exception, so a transient read failure is never mistaken for a corrupt pair.
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function isLiveKeyPairUsable(): bool
    {
        try {
            $record = $this->loadRecord();
            if ($record === null) {
                return false;
            }
            $this->validateLiveKeyPair($record);
        } catch (AgentSigningKeyStorageException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            return false;
        }

        return true;
    }

    /**
     * Whether the last load answered "no key" because the signing-key table does not exist,
     * as opposed to an existing table without a row. Only meaningful right after a load.
     */
    public function isStorageMissingTable(): bool
    {
        return $this->storageMissingTable;
    }

    /**
     * The managed row itself, for the push service, which keeps its state on the same row.
     * Always the identity-mapped instance, so a refresh after a row update reaches every reader.
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getRecord(): ?AgentSigningKeyRecord
    {
        return $this->loadRecord();
    }

    /**
     * Re-reads the managed row after a write that went past the identity map. Every such write
     * must be followed by this call, otherwise the next reader sees the pre-update values.
     */
    public function refreshAfterRowUpdate(): void
    {
        if ($this->record !== null && $this->entityManager->contains($this->record)) {
            $this->refreshRecord($this->record);
        }
    }

    public function getKeyId(): string
    {
        return self::deriveKeyId($this->getPublicKeyPem());
    }

    public function getFingerprint(): string
    {
        return self::deriveFingerprint($this->getPublicKeyPem());
    }

    /**
     * @throws RuntimeException When no keypair exists or the stored pair is unusable
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getPublicKeyPem(): string
    {
        return $this->ensureKeyPair()->getPublicKeyPem();
    }

    /**
     * For signing inside this plugin only - the private key must never leave the host.
     *
     * @throws RuntimeException When no keypair exists or the stored pair is unusable
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getPrivateKeyPem(): string
    {
        return $this->ensureKeyPair()->getPrivateKeyPem();
    }

    /**
     * Creates the first keypair; a live keypair is never overwritten, replacing it is a
     * regeneration. Losing the insert race to another node is not an error: the winner's row
     * is loaded and the generated pair discarded.
     *
     * @throws RuntimeException When a keypair exists, generation fails or the row cannot be written
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function generateKeyPair(): void
    {
        if ($this->hasKeyPair()) {
            throw new RuntimeException(
                'An agent signing keypair already exists. Replacing it is a regeneration, which transmits the successor chained to the current key first.',
                1755300001
            );
        }

        [$privateKeyPem, $publicKeyPem] = $this->generateRsaKeyPair();
        $this->agentSigningKeyRecordRepository->insertIfAbsent(new AgentSigningKeyRecord($privateKeyPem, $publicKeyPem));

        $this->record = null;
        if ($this->loadRecord() === null) {
            throw new RuntimeException('The agent signing keypair was written but could not be loaded back.', 1757000011);
        }
    }

    /**
     * @return array{0: string, 1: string} The private and the public key PEM
     * @throws RuntimeException When generation fails
     */
    private function generateRsaKeyPair(): array
    {
        $keyResource = openssl_pkey_new([
            'private_key_bits' => self::RSA_KEY_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ]);
        if ($keyResource === false) {
            throw new RuntimeException('Failed to generate an RSA keypair: ' . (string)openssl_error_string(), 1755300002);
        }

        $privateKeyPem = '';
        if (!openssl_pkey_export($keyResource, $privateKeyPem)) {
            throw new RuntimeException('Failed to export the generated private key: ' . (string)openssl_error_string(), 1755300003);
        }

        $keyDetails = openssl_pkey_get_details($keyResource);
        if ($keyDetails === false || !isset($keyDetails['key'])) {
            throw new RuntimeException('Failed to extract the public key from the generated keypair.', 1755300004);
        }

        return [$privateKeyPem, (string)$keyDetails['key']];
    }

    /**
     * Whether a regeneration was started but not yet confirmed by the backend, which requires
     * all three pending columns to be set.
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function hasPendingKeyPair(): bool
    {
        $record = $this->loadRecord();

        return $record !== null
            && $record->getPendingKid() !== null
            && $record->getPendingPrivateKeyPem() !== null
            && $record->getPendingPublicKeyPem() !== null;
    }

    /**
     * Creates the successor pair when none is waiting. Idempotent by default: every retry
     * transmits the same pending pair, and the relabel flag, once set, sticks to it until it is
     * committed.
     *
     * With $forceFresh a waiting successor is REPLACED by a newly minted one instead of reused.
     * A re-enrolment or a relabel must never announce a pair it did not mint: on a copy of another
     * installation the waiting pair can be the original's, and announcing it would hand this
     * installation's host the original's lineage. The replacement is one compare-and-swap, so
     * {@see hasPendingKeyPair} never observes "no pending pair" in between, and it owns the
     * relabel flag outright - a replacement never inherits the intent of the pair it discards.
     * Losing that swap to a concurrent regeneration is not an error: the winner's pair is reused,
     * and this caller's intent is written onto it in both directions - a relabel arms the flag,
     * a re-enrolment clears the one the winner's pair may carry.
     *
     * @param bool $relabel Re-register the installation under its pushed domain on confirmation
     * @param bool $forceFresh Replace a waiting successor instead of reusing it
     * @throws RuntimeException When no live keypair exists, generation fails or the row cannot be written
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function preparePendingKeyPair(bool $relabel = false, bool $forceFresh = false): void
    {
        $record = $this->requireRecord();
        $existingPendingKid = $record->getPendingKid();
        if ($existingPendingKid !== null) {
            if ($forceFresh) {
                if (!$this->replacePendingKeyPair($record, $existingPendingKid, $relabel)) {
                    $this->agentSigningKeyRecordRepository->updateInstallRow(['pendingRelabel' => $relabel]);
                    $this->refreshRecord($record);
                }

                return;
            }
            $this->stickRelabelFlag($record, $relabel);

            return;
        }

        [$privateKeyPem, $publicKeyPem] = $this->generateRsaKeyPair();
        $affectedRows = $this->agentSigningKeyRecordRepository->preparePendingIfNone(
            $privateKeyPem,
            $publicKeyPem,
            self::deriveKeyId($publicKeyPem),
            $relabel
        );
        $this->refreshRecord($record);

        if ($affectedRows === 0) {
            $this->stickRelabelFlag($record, $relabel);
        }
    }

    /**
     * @return bool True when this call replaced the waiting successor, false when a concurrent
     *              regeneration replaced or committed it first
     * @throws RuntimeException When generation fails or the row cannot be written
     */
    private function replacePendingKeyPair(AgentSigningKeyRecord $record, string $expectedPendingKid, bool $relabel): bool
    {
        [$privateKeyPem, $publicKeyPem] = $this->generateRsaKeyPair();
        $affectedRows = $this->agentSigningKeyRecordRepository->replacePendingIfKidMatches(
            $expectedPendingKid,
            $privateKeyPem,
            $publicKeyPem,
            self::deriveKeyId($publicKeyPem),
            $relabel
        );
        $this->refreshRecord($record);

        return $affectedRows > 0;
    }

    /**
     * Drops the relabel intent from the waiting successor, leaving the pair itself alone: a
     * relabel whose push failed must not be completed later by the automatic authorization push,
     * with no administrator present. A keyless installation has nothing to clear.
     *
     * @throws RuntimeException When the row cannot be written
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function clearPendingRelabel(): void
    {
        $record = $this->loadRecord();
        if ($record === null) {
            return;
        }

        $this->agentSigningKeyRecordRepository->updateInstallRow(['pendingRelabel' => false]);
        $this->refreshRecord($record);
    }

    private function stickRelabelFlag(AgentSigningKeyRecord $record, bool $relabel): void
    {
        if (!$relabel || $record->isPendingRelabel()) {
            return;
        }

        $this->agentSigningKeyRecordRepository->updateInstallRow(['pendingRelabel' => true]);
        $this->refreshRecord($record);
    }

    /**
     * Whether the waiting pending pair asked to re-register the installation under its pushed
     * domain. Without a pending pair the flag means nothing.
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function isPendingRelabel(): bool
    {
        return $this->hasPendingKeyPair() && (bool)$this->loadRecord()?->isPendingRelabel();
    }

    /**
     * When the pending pair was last transmitted, as a Unix timestamp. Null right after it was
     * created, so the first retry is never delayed.
     *
     * @return int|null Null when there is no pending pair or it was never transmitted
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getPendingKeyPairRetriedAt(): ?int
    {
        if (!$this->hasPendingKeyPair()) {
            return null;
        }

        return $this->loadRecord()?->getPendingRetriedAt()?->getTimestamp();
    }

    /**
     * Stamps the pending pair as transmitted now, as the retry back-off reads it.
     *
     * @return bool False when there is no pending pair to stamp
     * @throws RuntimeException When the row cannot be written
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function markPendingKeyPairRetried(): bool
    {
        if (!$this->hasPendingKeyPair()) {
            return false;
        }

        $record = $this->requireRecord();
        $this->agentSigningKeyRecordRepository->updateInstallRow(['pendingRetriedAt' => new DateTimeImmutable()]);
        $this->refreshRecord($record);

        return true;
    }

    /**
     * @throws RuntimeException When no pending pair exists or its public key is unusable
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getPendingPublicKeyPem(): string
    {
        $publicKeyPem = $this->hasPendingKeyPair() ? $this->loadRecord()?->getPendingPublicKeyPem() : null;
        if ($publicKeyPem === null || trim($publicKeyPem) === '' || openssl_pkey_get_public($publicKeyPem) === false) {
            throw new RuntimeException(
                'The pending agent signing public key is missing, empty or not a parseable PEM public key.',
                1756800001
            );
        }

        return $publicKeyPem;
    }

    /**
     * For signing inside this plugin only: the pending pair vouches for its own host set on the
     * rotation push, before it is the live pair. The private key must never leave the host.
     *
     * @throws RuntimeException When no pending pair exists or its private key is unusable
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function getPendingPrivateKeyPem(): string
    {
        $privateKeyPem = $this->hasPendingKeyPair() ? $this->loadRecord()?->getPendingPrivateKeyPem() : null;
        if ($privateKeyPem === null || trim($privateKeyPem) === '' || openssl_pkey_get_private($privateKeyPem) === false) {
            throw new RuntimeException(
                'The pending agent signing private key is missing, empty or not a parseable PEM private key.',
                1757100001
            );
        }

        return $privateKeyPem;
    }

    /**
     * Promotes the pending pair to the live pair, but only while the pending key still is the
     * one the caller names as confirmed. True means this call promoted it, false that the
     * confirmed key already was the live pair, and an exception that a later regeneration
     * replaced the pending pair - so neither answer may be treated as "nothing to do".
     *
     * @param string $expectedKid The kid of the pending public key the backend confirmed
     * @return bool True when this call promoted the pending pair, false when the confirmed key
     *              already was the live pair
     * @throws RuntimeException When the pending pair is a different key, when neither the
     *                          pending nor the live pair is the confirmed key, or the row
     *                          cannot be written
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    public function commitPendingKeyPair(string $expectedKid): bool
    {
        $record = $this->requireRecord();
        $affectedRows = $this->agentSigningKeyRecordRepository->commitPendingIfKidMatches($expectedKid);
        $this->refreshRecord($record);

        if ($affectedRows === 1) {
            return true;
        }

        if (self::deriveKeyId($record->getPublicKeyPem()) === $expectedKid) {
            return false;
        }

        throw new RuntimeException(
            'The pending agent signing keypair is not the key that was confirmed: a later regeneration replaced it. Neither pair was touched.',
            1756800004
        );
    }

    /**
     * Lowercase hex SHA-256 of the DER-encoded SubjectPublicKeyInfo.
     *
     * @throws RuntimeException When the given string is not a valid PEM public key
     */
    public static function deriveKeyId(string $publicKeyPem): string
    {
        return hash('sha256', self::pemToDer($publicKeyPem));
    }

    /**
     * The same SHA-256 as {@see deriveKeyId}, as uppercase hex byte pairs joined by colons.
     *
     * @throws RuntimeException When the given string is not a valid PEM public key
     */
    public static function deriveFingerprint(string $publicKeyPem): string
    {
        return implode(':', str_split(strtoupper(self::deriveKeyId($publicKeyPem)), 2));
    }

    /**
     * The live pair, loaded and validated on every call - never generated, since a commit can
     * replace the live columns under a refresh.
     *
     * @throws RuntimeException When no keypair exists or the stored pair is unusable
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    private function ensureKeyPair(): AgentSigningKeyRecord
    {
        $record = $this->requireRecord();
        $this->validateLiveKeyPair($record);

        return $record;
    }

    /**
     * Refuses a row whose two halves do not form one keypair: the push derives the kid from
     * the public column but signs with the private one, so a split pair would silently
     * re-enrol the installation under a key nothing can verify.
     *
     * @throws RuntimeException When either key is empty or not a parseable PEM, or the two do
     *                          not form one keypair
     */
    private function validateLiveKeyPair(AgentSigningKeyRecord $record): void
    {
        $privateKeyPem = $record->getPrivateKeyPem();
        $publicKeyPem = $record->getPublicKeyPem();

        $privateKey = trim($privateKeyPem) === '' ? false : openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new RuntimeException(
                'The stored agent signing private key is empty or not a parseable PEM private key.',
                1755300011
            );
        }
        if (trim($publicKeyPem) === '' || openssl_pkey_get_public($publicKeyPem) === false) {
            throw new RuntimeException(
                'The stored agent signing public key is empty or not a parseable PEM public key.',
                1755300012
            );
        }

        $privateKeyDetails = openssl_pkey_get_details($privateKey);
        $derivedPublicKeyPem = $privateKeyDetails === false || !isset($privateKeyDetails['key']) ? null : (string)$privateKeyDetails['key'];
        if ($derivedPublicKeyPem === null || self::deriveKeyId($derivedPublicKeyPem) !== self::deriveKeyId($publicKeyPem)) {
            throw new RuntimeException(
                'The stored agent signing keys do not belong together: the public key is not the one of the private key.',
                1755300016
            );
        }
    }

    /**
     * @throws RuntimeException When no keypair exists
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    private function requireRecord(): AgentSigningKeyRecord
    {
        $record = $this->loadRecord();
        if ($record === null) {
            throw new RuntimeException(
                'No agent signing keypair exists yet. It is created by the first authorization or by ./flow agentkey:generate.',
                1755300005
            );
        }

        return $record;
    }

    /**
     * The row of this installation, memoized for the request; a detached row is reloaded and
     * "no row" is re-asked every time, since another writer can create it mid-request. A
     * missing table is the one failure that means "no key" rather than "could not load", and
     * Flow's Query reports it as an unchained DatabaseStructureException.
     *
     * @throws AgentSigningKeyStorageException When the row could not be loaded
     */
    private function loadRecord(): ?AgentSigningKeyRecord
    {
        if ($this->record !== null && $this->entityManager->contains($this->record)) {
            return $this->record;
        }

        try {
            $this->record = $this->agentSigningKeyRecordRepository->findInstallRecord();
            $this->storageMissingTable = false;
        } catch (Throwable $throwable) {
            if ($this->isMissingTable($throwable)) {
                $this->logMissingTableOnce();
                $this->record = null;
                $this->storageMissingTable = true;

                return null;
            }

            throw new AgentSigningKeyStorageException(
                'The agent signing keypair could not be loaded from the database.',
                1757000012,
                $throwable
            );
        }

        return $this->record;
    }

    private function refreshRecord(AgentSigningKeyRecord $record): void
    {
        $this->entityManager->refresh($record);
    }

    private function isMissingTable(Throwable $throwable): bool
    {
        return $throwable instanceof DatabaseStructureException;
    }

    private function logMissingTableOnce(): void
    {
        if ($this->missingTableLogged) {
            return;
        }
        $this->missingTableLogged = true;

        $this->logger?->warning(
            'The NEOSidekick agent signing-key table does not exist: the database migration has not run yet (./flow doctrine:migrate). Until then this installation has no signing key.',
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }

    /**
     * @throws RuntimeException When the given string is not a valid PEM public key
     */
    private static function pemToDer(string $publicKeyPem): string
    {
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $publicKeyPem, $matches)) {
            throw new RuntimeException('The given key is not a PEM-encoded public key.', 1755300008);
        }

        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
        if ($der === false || $der === '') {
            throw new RuntimeException('The given PEM public key does not contain valid base64 content.', 1755300009);
        }

        return $der;
    }
}

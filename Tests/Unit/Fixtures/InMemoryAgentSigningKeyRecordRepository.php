<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Fixtures;

use DateTimeInterface;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use ReflectionProperty;
use RuntimeException;

/**
 * The signing-key row without a database, for the unit suites of AgentKeyPairService,
 * AgentSigningKeyPushService, AgentKeyCommandController and AgentTokenService.
 *
 * It models exactly the database semantics the real repository rests on, and nothing else:
 *
 *  - the UNIQUE `slot` column: the first {@see insertIfAbsent} creates the row, every later
 *    one returns false and leaves the existing row alone (the lost multi-node race);
 *  - the prepare compare-and-swap on `pendingKid IS NULL`: {@see preparePendingIfNone}
 *    affects 0 rows while a pending pair exists, so a second regeneration reuses it;
 *  - the replace compare-and-swap on `pendingKid = :expected`: {@see replacePendingIfKidMatches}
 *    affects 0 rows on a stale kid, and on a match swaps the pending pair for a new one while
 *    writing the relabel flag unconditionally, the way the real statement does;
 *  - the commit compare-and-swap on `pendingKid = :expected`: {@see commitPendingIfKidMatches}
 *    affects 0 rows on a stale kid, and on a match copies pending to live and clears the
 *    pending state in the SET order of the real statement;
 *  - the plain bookkeeping update: {@see updateInstallRow} writes the given properties, without
 *    the real repository's whitelist (a unit test that passes an unknown property is wrong, not
 *    a scenario).
 *
 * Every write mutates the very entity object {@see findInstallRecord} hands out, so a service
 * whose EntityManager::refresh() is a no-op mock still observes every write - there is no
 * identity map to be stale behind. That makes this fake BLIND to a missing refresh after a row
 * update; the refresh-sensitive coverage lives in the functional
 * ConfigurationModuleSigningKeyTest, which drives the same paths against real persistence.
 *
 * {@see failNextWrite} and {@see failLoads} let a test provoke the two failure shapes the
 * services distinguish: a write failure with the real repository's fixed PEM-free message, and
 * a load failure (which AgentKeyPairService turns into AgentSigningKeyStorageException).
 *
 * Extends the real repository so type hints and `@Flow\Inject` slots accept it; the parent
 * constructor only derives the entity class name and touches no Flow infrastructure.
 */
class InMemoryAgentSigningKeyRecordRepository extends AgentSigningKeyRecordRepository
{
    private ?AgentSigningKeyRecord $record = null;

    private ?\Throwable $nextWriteFailure = null;

    private ?\Throwable $loadFailure = null;

    /**
     * Seeds the row directly, bypassing the insert race - for tests that start from an
     * installation with a key.
     */
    public function seed(AgentSigningKeyRecord $record): void
    {
        $this->record = $record;
    }

    public function clear(): void
    {
        $this->record = null;
    }

    /**
     * The next write (insert, prepare, commit or row update) throws the given exception - or, without one,
     * the real repository's fixed-message RuntimeException - and is then forgotten.
     */
    public function failNextWrite(?\Throwable $throwable = null): void
    {
        $this->nextWriteFailure = $throwable ?? new RuntimeException('signing-key row write failed', 1757000001);
    }

    /**
     * Every {@see findInstallRecord} throws the given exception until called with null.
     */
    public function failLoads(?\Throwable $throwable): void
    {
        $this->loadFailure = $throwable;
    }

    public function findInstallRecord(): ?AgentSigningKeyRecord
    {
        if ($this->loadFailure !== null) {
            throw $this->loadFailure;
        }

        return $this->record;
    }

    public function insertIfAbsent(AgentSigningKeyRecord $record): bool
    {
        $this->throwPendingWriteFailure();
        if ($this->record !== null) {
            return false;
        }

        $this->record = $record;

        return true;
    }

    public function preparePendingIfNone(
        string $pendingPrivateKeyPem,
        string $pendingPublicKeyPem,
        string $pendingKid,
        bool $pendingRelabel
    ): int {
        $this->throwPendingWriteFailure();
        if ($this->record === null || $this->record->getPendingKid() !== null) {
            return 0;
        }

        $this->setColumn('pendingPrivateKeyPem', $pendingPrivateKeyPem);
        $this->setColumn('pendingPublicKeyPem', $pendingPublicKeyPem);
        $this->setColumn('pendingKid', $pendingKid);
        $this->setColumn('pendingRelabel', $pendingRelabel);
        $this->setColumn('pendingRetriedAt', null);

        return 1;
    }

    public function replacePendingIfKidMatches(
        string $expectedPendingKid,
        string $privateKeyPem,
        string $publicKeyPem,
        string $newKid,
        bool $relabel
    ): int {
        $this->throwPendingWriteFailure();
        if ($this->record === null || $this->record->getPendingKid() !== $expectedPendingKid) {
            return 0;
        }

        $this->setColumn('pendingPrivateKeyPem', $privateKeyPem);
        $this->setColumn('pendingPublicKeyPem', $publicKeyPem);
        $this->setColumn('pendingKid', $newKid);
        $this->setColumn('pendingRelabel', $relabel);
        $this->setColumn('pendingRetriedAt', null);

        return 1;
    }

    public function commitPendingIfKidMatches(string $expectedPendingKid): int
    {
        $this->throwPendingWriteFailure();
        if ($this->record === null || $this->record->getPendingKid() !== $expectedPendingKid) {
            return 0;
        }

        $this->setColumn('privateKeyPem', (string)$this->record->getPendingPrivateKeyPem());
        $this->setColumn('publicKeyPem', (string)$this->record->getPendingPublicKeyPem());
        $this->setColumn('pendingPrivateKeyPem', null);
        $this->setColumn('pendingPublicKeyPem', null);
        $this->setColumn('pendingKid', null);
        $this->setColumn('pendingRelabel', false);
        $this->setColumn('pendingRetriedAt', null);

        return 1;
    }

    /**
     * @param array<string, string|bool|DateTimeInterface|null> $columns
     */
    public function updateInstallRow(array $columns): void
    {
        $this->throwPendingWriteFailure();
        if ($this->record === null) {
            return;
        }

        foreach ($columns as $propertyName => $value) {
            $this->setColumn($propertyName, $value);
        }
    }

    private function throwPendingWriteFailure(): void
    {
        if ($this->nextWriteFailure === null) {
            return;
        }

        $throwable = $this->nextWriteFailure;
        $this->nextWriteFailure = null;
        throw $throwable;
    }

    /**
     * The entity has no setters at all - only the database writes its columns, and the fake
     * IS the database here.
     *
     * @param string|bool|DateTimeInterface|null $value
     */
    private function setColumn(string $propertyName, $value): void
    {
        $property = new ReflectionProperty(AgentSigningKeyRecord::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($this->record, $value);
    }
}

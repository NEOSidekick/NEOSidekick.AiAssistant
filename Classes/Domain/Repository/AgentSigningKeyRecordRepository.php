<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Domain\Repository;

use DateTimeInterface;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use Neos\Flow\Utility\Algorithms;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use RuntimeException;
use Throwable;

/**
 * The four database operations behind the installation's signing key. They write
 * through DBAL rather than the ORM, so a failed write cannot close the EntityManager
 * and silently drop every later write of the request. Every write failure becomes a
 * {@see RuntimeException} with a fixed, PEM-free message, because the driver's own
 * message carries the bound private key.
 *
 * @Flow\Scope("singleton")
 */
class AgentSigningKeyRecordRepository extends Repository
{
    /**
     * The commit statement, public so a test can pin the order of its SET list against
     * the SQL Doctrine actually generates.
     */
    public const COMMIT_PENDING_DQL = 'UPDATE ' . AgentSigningKeyRecord::class . ' r'
        . ' SET r.privateKeyPem = r.pendingPrivateKeyPem,'
        . ' r.publicKeyPem = r.pendingPublicKeyPem,'
        . ' r.pendingPrivateKeyPem = NULL,'
        . ' r.pendingPublicKeyPem = NULL,'
        . ' r.pendingKid = NULL,'
        . ' r.pendingRelabel = false,'
        . ' r.pendingRetriedAt = NULL'
        . ' WHERE r.slot = :slot AND r.pendingKid = :expectedPendingKid';

    /**
     * The replace statement. It owns the relabel flag: `pendingRelabel` is assigned
     * unconditionally, so a successor that replaces another one never inherits the flag of the
     * pair it discards.
     */
    private const REPLACE_PENDING_DQL = 'UPDATE ' . AgentSigningKeyRecord::class . ' r'
        . ' SET r.pendingPrivateKeyPem = :pendingPrivateKeyPem,'
        . ' r.pendingPublicKeyPem = :pendingPublicKeyPem,'
        . ' r.pendingKid = :newKid,'
        . ' r.pendingRelabel = :pendingRelabel,'
        . ' r.pendingRetriedAt = NULL'
        . ' WHERE r.slot = :slot AND r.pendingKid = :expectedPendingKid';

    /**
     * The physical table name Flow's annotation driver derives.
     */
    private const RECORD_TABLE = 'neosidekick_aiassistant_domain_model_agentsigningkeyrecord';

    /**
     * The only columns {@see updateInstallRow()} may touch, keyed by entity property name.
     * The PEM columns and the pending kid are excluded on purpose: only the three guarded
     * statements may write key material or the compare-and-swap predicate.
     *
     * @var array<string, array{0: string, 1: string}> property => [column, DBAL type]
     */
    private const UPDATABLE_COLUMNS = [
        'pendingRelabel' => ['pendingrelabel', Types::BOOLEAN],
        'pendingRetriedAt' => ['pendingretriedat', Types::DATETIME_MUTABLE],
        'pushKid' => ['pushkid', Types::STRING],
        'pushTarget' => ['pushtarget', Types::STRING],
        'pushStatus' => ['pushstatus', Types::STRING],
        'pushPluginVersion' => ['pushpluginversion', Types::STRING],
        'pushedAt' => ['pushedat', Types::DATETIME_MUTABLE],
        'pushInstallRootKid' => ['pushinstallrootkid', Types::STRING],
        'pushReenrolled' => ['pushreenrolled', Types::BOOLEAN],
        'pushReenrolledAt' => ['pushreenrolledat', Types::DATETIME_MUTABLE],
        'pushRegisteredDomain' => ['pushregistereddomain', Types::STRING],
    ];

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * The one row of this installation, or null when it has no signing key yet.
     */
    public function findInstallRecord(): ?AgentSigningKeyRecord
    {
        $query = $this->createQuery();
        /** @var AgentSigningKeyRecord|null $record */
        $record = $query->matching($query->equals('slot', AgentSigningKeyRecord::SLOT_INSTALL))
            ->execute()
            ->getFirst();

        return $record;
    }

    /**
     * Creates the installation's row, or reports that another node won the race.
     *
     * Never call this inside an explicit transaction: on PostgreSQL the unique violation
     * would abort the surrounding transaction rather than just this statement. The caller
     * must reload either way, because on false the winner's key is the installation's key.
     *
     * @return bool True when this call created the row, false when a concurrent one did
     * @throws RuntimeException On any other write failure, with a PEM-free message
     */
    public function insertIfAbsent(AgentSigningKeyRecord $record): bool
    {
        try {
            $this->entityManager->getConnection()->insert(
                self::RECORD_TABLE,
                [
                    'persistence_object_identifier' => $this->identifierOf($record),
                    'slot' => AgentSigningKeyRecord::SLOT_INSTALL,
                    'privatekeypem' => $record->getPrivateKeyPem(),
                    'publickeypem' => $record->getPublicKeyPem(),
                    'pendingrelabel' => $record->isPendingRelabel(),
                ],
                ['pendingrelabel' => Types::BOOLEAN]
            );
        } catch (UniqueConstraintViolationException $exception) {
            return false;
        } catch (Throwable $throwable) {
            throw $this->failedWrite($throwable, 1757000001);
        }

        return true;
    }

    /**
     * Starts a rotation, but only if none is running: a compare-and-swap on
     * `pendingKid IS NULL`. 0 affected rows means another regeneration got there first;
     * the caller must reload and reuse that pending pair rather than chain a second one.
     *
     * @return int Number of affected rows (0 or 1)
     * @throws RuntimeException On a write failure, with a PEM-free message
     */
    public function preparePendingIfNone(
        string $pendingPrivateKeyPem,
        string $pendingPublicKeyPem,
        string $pendingKid,
        bool $pendingRelabel
    ): int {
        $query = $this->entityManager->createQuery(
            'UPDATE ' . AgentSigningKeyRecord::class . ' r'
            . ' SET r.pendingPrivateKeyPem = :pendingPrivateKeyPem,'
            . ' r.pendingPublicKeyPem = :pendingPublicKeyPem,'
            . ' r.pendingKid = :pendingKid,'
            . ' r.pendingRelabel = :pendingRelabel,'
            . ' r.pendingRetriedAt = NULL'
            . ' WHERE r.slot = :slot AND r.pendingKid IS NULL'
        );

        try {
            return (int)$query->execute([
                'pendingPrivateKeyPem' => $pendingPrivateKeyPem,
                'pendingPublicKeyPem' => $pendingPublicKeyPem,
                'pendingKid' => $pendingKid,
                'pendingRelabel' => $pendingRelabel,
                'slot' => AgentSigningKeyRecord::SLOT_INSTALL,
            ]);
        } catch (Throwable $throwable) {
            throw $this->failedWrite($throwable, 1757000002);
        }
    }

    /**
     * Promotes the pending pair to live, but only if it is still the pair the backend
     * confirmed: a compare-and-swap on `pendingKid`. 0 affected rows means the pending
     * pair was replaced or already committed, which the caller must never report as a
     * successful rotation.
     *
     * The SET order of {@see COMMIT_PENDING_DQL} is load-bearing: MySQL evaluates a SET
     * list left to right using the already-updated values, so the two live-from-pending
     * assignments must stay ahead of the resets.
     *
     * @return int Number of affected rows (0 or 1)
     * @throws RuntimeException On a write failure, with a PEM-free message
     */
    public function commitPendingIfKidMatches(string $expectedPendingKid): int
    {
        $query = $this->entityManager->createQuery(self::COMMIT_PENDING_DQL);

        try {
            return (int)$query->execute([
                'slot' => AgentSigningKeyRecord::SLOT_INSTALL,
                'expectedPendingKid' => $expectedPendingKid,
            ]);
        } catch (Throwable $throwable) {
            throw $this->failedWrite($throwable, 1757000003);
        }
    }

    /**
     * Replaces a waiting successor with a freshly minted one, but only while the pending pair
     * still is the one the caller looked at: a compare-and-swap on `pendingKid`. 0 affected rows
     * means a concurrent regeneration replaced or committed it in the meantime, and the caller
     * must reuse whatever is there now rather than mint a third key.
     *
     * One statement rather than a discard followed by a prepare, so `pendingKid` is never NULL
     * in between: a concurrent editor authorize would otherwise fall onto the live-key branch.
     *
     * The relabel flag is written unconditionally, true or false: this statement owns it, so a
     * re-enrolment can never inherit the relabel intent of the pair it replaces.
     *
     * @return int Number of affected rows (0 or 1)
     * @throws RuntimeException On a write failure, with a PEM-free message
     */
    public function replacePendingIfKidMatches(
        string $expectedPendingKid,
        string $privateKeyPem,
        string $publicKeyPem,
        string $newKid,
        bool $relabel
    ): int {
        $query = $this->entityManager->createQuery(self::REPLACE_PENDING_DQL);

        try {
            return (int)$query->execute([
                'pendingPrivateKeyPem' => $privateKeyPem,
                'pendingPublicKeyPem' => $publicKeyPem,
                'newKid' => $newKid,
                'pendingRelabel' => $relabel,
                'slot' => AgentSigningKeyRecord::SLOT_INSTALL,
                'expectedPendingKid' => $expectedPendingKid,
            ]);
        } catch (Throwable $throwable) {
            throw $this->failedWrite($throwable, 1757000004);
        }
    }

    /**
     * Writes bookkeeping columns of the install row - the relabel intent, the retry stamp
     * and the push state - past the identity map, keyed by entity property name; only the
     * properties in {@see UPDATABLE_COLUMNS} are accepted. The caller owns the managed
     * entity and must refresh it afterwards.
     *
     * @param array<string, string|bool|DateTimeInterface|null> $columns property => value
     * @throws RuntimeException On an unknown property, or on a write failure with a PEM-free message
     */
    public function updateInstallRow(array $columns): void
    {
        $data = [];
        $types = [];
        foreach ($columns as $propertyName => $value) {
            if (!isset(self::UPDATABLE_COLUMNS[$propertyName])) {
                throw new RuntimeException('signing-key row update refused: not an updatable column', 1757000021);
            }
            [$columnName, $type] = self::UPDATABLE_COLUMNS[$propertyName];
            $data[$columnName] = $value;
            $types[$columnName] = $type;
        }
        if ($data === []) {
            return;
        }

        try {
            $this->entityManager->getConnection()->update(
                self::RECORD_TABLE,
                $data,
                ['slot' => AgentSigningKeyRecord::SLOT_INSTALL],
                $types
            );
        } catch (Throwable $throwable) {
            throw $this->failedWrite($throwable, 1757000022);
        }
    }

    /**
     * The identifier Flow's proxy assigned in the constructor. A non-proxy instance has no
     * such property and gets a throwaway identifier instead.
     */
    private function identifierOf(AgentSigningKeyRecord $record): string
    {
        if (!property_exists($record, 'Persistence_Object_Identifier')) {
            return Algorithms::generateUUID();
        }

        $identifier = ObjectAccess::getProperty($record, 'Persistence_Object_Identifier', true);
        if (!is_string($identifier) || $identifier === '') {
            $identifier = Algorithms::generateUUID();
            ObjectAccess::setProperty($record, 'Persistence_Object_Identifier', $identifier, true);
        }

        return $identifier;
    }

    /**
     * The one wrapper every write failure goes through: the message is fixed, never the
     * driver's, which carries the bound PEM. A missing table names its remedy.
     */
    private function failedWrite(Throwable $throwable, int $code): RuntimeException
    {
        $message = $throwable instanceof TableNotFoundException
            ? 'signing-key row write failed: the database migration has not run yet (./flow doctrine:migrate)'
            : 'signing-key row write failed';

        return new RuntimeException($message, $code, $throwable);
    }
}

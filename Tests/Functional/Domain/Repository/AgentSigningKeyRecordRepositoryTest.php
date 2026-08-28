<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Domain\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Neos\Flow\Tests\FunctionalTestCase;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use RuntimeException;

/**
 * Functional tests for the database semantics the signing-key row rests on, against real
 * persistence: the multi-node insert race, the two-administrator rotation races (prepare
 * and commit), the whitelisted bookkeeping update, and the promise that no write failure
 * can put the private key into an exception message.
 *
 * Every assertion about the outcome of a write reads the row back through raw DBAL. All
 * writes deliberately bypass the ORM - the insert to keep the EntityManager open on a lost
 * race, the updates to be atomic - so an entity loaded earlier in the test would still
 * report its stale identity-map state.
 */
class AgentSigningKeyRecordRepositoryTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    protected static $testablePersistenceEnabled = true;

    protected AgentSigningKeyRecordRepository $recordRepository;

    public function setUp(): void
    {
        parent::setUp();
        $this->recordRepository = $this->objectManager->get(AgentSigningKeyRecordRepository::class);
    }

    /**
     * Two app nodes generating the installation's first keypair at the same moment: the
     * unique `slot` column decides, and - crucially - the loser's EntityManager survives
     * it. An ORM flush would have closed it here, after which Flow's persistAll() only
     * logs and silently drops every later write of that request.
     *
     * @test
     */
    public function onlyTheFirstInsertCreatesTheRowAndALostRaceLeavesPersistenceUsable(): void
    {
        [$winnerPrivateKeyPem, $winnerPublicKeyPem] = $this->generateSigningKeyPair();
        [$loserPrivateKeyPem, $loserPublicKeyPem] = $this->generateSigningKeyPair();

        $winnerCreatedTheRow = $this->recordRepository->insertIfAbsent(
            new AgentSigningKeyRecord($winnerPrivateKeyPem, $winnerPublicKeyPem)
        );
        $loserCreatedTheRow = $this->recordRepository->insertIfAbsent(
            new AgentSigningKeyRecord($loserPrivateKeyPem, $loserPublicKeyPem)
        );

        self::assertTrue($winnerCreatedTheRow, 'the first insert must create the row');
        self::assertFalse($loserCreatedTheRow, 'the second insert must report the lost race, not throw');
        self::assertSame(1, $this->countSigningKeyRecords(), 'the unique slot must keep the table at one row');
        self::assertSame(
            $winnerPrivateKeyPem,
            $this->readSigningKeyColumn('privatekeypem'),
            'the loser must never overwrite the winner key'
        );

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        self::assertTrue($entityManager->isOpen(), 'a lost insert race must not close the EntityManager');

        $this->persistenceManager->persistAll();

        $record = $this->recordRepository->findInstallRecord();
        self::assertInstanceOf(
            AgentSigningKeyRecord::class,
            $record,
            'the row written through DBAL must load as a managed entity'
        );
        self::assertSame($winnerPublicKeyPem, $record->getPublicKeyPem());
        self::assertSame(
            $record,
            $this->recordRepository->findInstallRecord(),
            'the loaded entity must be the identity-mapped one, so the service can cache it'
        );
    }

    /**
     * Two administrators pressing Regenerate at once: the second prepare must find the
     * pending slot taken and change nothing, so both rotations chain the SAME successor
     * to the live key. Two different successors would make the backend revoke the live
     * key on the first of them and re-enrol the installation on the second.
     *
     * @test
     */
    public function preparingASecondPendingPairAffectsNoRowAndKeepsTheFirstPendingKid(): void
    {
        $this->createInstallRecord();
        [$firstPrivateKeyPem, $firstPublicKeyPem] = $this->generateSigningKeyPair();
        [$secondPrivateKeyPem, $secondPublicKeyPem] = $this->generateSigningKeyPair();

        $firstAffectedRows = $this->recordRepository->preparePendingIfNone(
            $firstPrivateKeyPem,
            $firstPublicKeyPem,
            'first-pending-kid',
            true
        );
        $secondAffectedRows = $this->recordRepository->preparePendingIfNone(
            $secondPrivateKeyPem,
            $secondPublicKeyPem,
            'second-pending-kid',
            false
        );

        self::assertSame(1, $firstAffectedRows, 'the first prepare must win the empty pending slot');
        self::assertSame(0, $secondAffectedRows, 'the second prepare must not replace the pending pair');
        self::assertSame('first-pending-kid', $this->readSigningKeyColumn('pendingkid'));
        self::assertSame($firstPrivateKeyPem, $this->readSigningKeyColumn('pendingprivatekeypem'));
        self::assertSame(
            1,
            (int)$this->readSigningKeyColumn('pendingrelabel'),
            'the losing prepare must not clear the first rotation relabel intent'
        );
    }

    /**
     * The retry stamp belongs to the pending pair it was stamped for. A fresh pending pair
     * must be pushed right away, so the prepare clears the stamp a previous (committed or
     * otherwise gone) pair left behind - in the same statement, not in a second write.
     *
     * @test
     */
    public function preparingAPendingPairClearsTheRetryStampOfThePreviousOne(): void
    {
        $this->createInstallRecord();
        $this->recordRepository->updateInstallRow(['pendingRetriedAt' => new DateTimeImmutable('2026-08-17 10:00:00')]);
        self::assertSame('2026-08-17 10:00:00', $this->readSigningKeyColumn('pendingretriedat'), 'the stamp is there to be cleared');
        [$pendingPrivateKeyPem, $pendingPublicKeyPem] = $this->generateSigningKeyPair();

        $affectedRows = $this->recordRepository->preparePendingIfNone($pendingPrivateKeyPem, $pendingPublicKeyPem, 'pending-kid', false);

        self::assertSame(1, $affectedRows);
        self::assertNull($this->readSigningKeyColumn('pendingretriedat'), 'a fresh pending pair starts without a retry stamp');
    }

    /**
     * A re-enrolment or a relabel replaces the waiting successor rather than announcing one it
     * did not mint. It is ONE statement: `pendingkid` is never NULL in between, so a concurrent
     * editor authorize stays on the pending branch instead of pushing the shared live key.
     *
     * The statement owns the relabel flag in both directions - `stickRelabelFlag()` could only
     * ever set it - so the successor never inherits the intent of the pair it replaces.
     *
     * @test
     */
    public function replacingThePendingPairSwapsTheSuccessorAndWritesTheRelabelFlagInBothDirections(): void
    {
        [$livePrivateKeyPem, $livePublicKeyPem] = $this->createInstallRecord();
        [$inheritedPrivateKeyPem, $inheritedPublicKeyPem] = $this->generateSigningKeyPair();
        [$mintedPrivateKeyPem, $mintedPublicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->preparePendingIfNone($inheritedPrivateKeyPem, $inheritedPublicKeyPem, 'inherited-kid', false);
        $this->recordRepository->updateInstallRow(['pendingRetriedAt' => new DateTimeImmutable('2026-08-17 10:00:00')]);

        $affectedRows = $this->recordRepository->replacePendingIfKidMatches(
            'inherited-kid',
            $mintedPrivateKeyPem,
            $mintedPublicKeyPem,
            'minted-kid',
            true
        );

        self::assertSame(1, $affectedRows);
        self::assertSame('minted-kid', $this->readSigningKeyColumn('pendingkid'));
        self::assertSame($mintedPrivateKeyPem, $this->readSigningKeyColumn('pendingprivatekeypem'));
        self::assertSame($mintedPublicKeyPem, $this->readSigningKeyColumn('pendingpublickeypem'));
        self::assertSame(1, (int)$this->readSigningKeyColumn('pendingrelabel'), 'the statement writes the flag itself');
        self::assertNull($this->readSigningKeyColumn('pendingretriedat'), 'a fresh successor is never delayed');
        self::assertSame($livePrivateKeyPem, $this->readSigningKeyColumn('privatekeypem'), 'the live pair is untouched');
        self::assertSame($livePublicKeyPem, $this->readSigningKeyColumn('publickeypem'));

        [$secondPrivateKeyPem, $secondPublicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->replacePendingIfKidMatches('minted-kid', $secondPrivateKeyPem, $secondPublicKeyPem, 'second-kid', false);

        self::assertSame('second-kid', $this->readSigningKeyColumn('pendingkid'));
        self::assertSame(
            0,
            (int)$this->readSigningKeyColumn('pendingrelabel'),
            'the replacement must not inherit the relabel intent of the pair it discards'
        );
    }

    /**
     * The other administrator replaced or committed the successor first: the compare-and-swap
     * must affect no row at all, so the caller reuses the winner's pair instead of minting a
     * third key nobody chained anything to.
     *
     * @test
     */
    public function replacingAStalePendingKidAffectsNoRowAndKeepsTheWinnersSuccessor(): void
    {
        $this->createInstallRecord();
        [$winnerPrivateKeyPem, $winnerPublicKeyPem] = $this->generateSigningKeyPair();
        [$loserPrivateKeyPem, $loserPublicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->preparePendingIfNone($winnerPrivateKeyPem, $winnerPublicKeyPem, 'winner-kid', true);

        $affectedRows = $this->recordRepository->replacePendingIfKidMatches(
            'a-kid-that-was-superseded',
            $loserPrivateKeyPem,
            $loserPublicKeyPem,
            'loser-kid',
            false
        );

        self::assertSame(0, $affectedRows);
        self::assertSame('winner-kid', $this->readSigningKeyColumn('pendingkid'));
        self::assertSame($winnerPrivateKeyPem, $this->readSigningKeyColumn('pendingprivatekeypem'));
        self::assertSame(1, (int)$this->readSigningKeyColumn('pendingrelabel'), 'the winner\'s intent is untouched');
    }

    /**
     * The confirmation of a superseded successor. Promoting it would put a key the
     * backend has already revoked into the live columns while the module reports
     * "confirmed", so the compare-and-swap must affect no row at all.
     *
     * @test
     */
    public function committingAStaleKidAffectsNoRowAndLeavesTheLiveKeyAlone(): void
    {
        [$livePrivateKeyPem] = $this->createInstallRecord();
        [$pendingPrivateKeyPem, $pendingPublicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->preparePendingIfNone(
            $pendingPrivateKeyPem,
            $pendingPublicKeyPem,
            'current-pending-kid',
            false
        );

        $affectedRows = $this->recordRepository->commitPendingIfKidMatches('superseded-pending-kid');

        self::assertSame(0, $affectedRows, 'a stale kid must never commit');
        self::assertSame($livePrivateKeyPem, $this->readSigningKeyColumn('privatekeypem'), 'the live key must be untouched');
        self::assertSame('current-pending-kid', $this->readSigningKeyColumn('pendingkid'), 'the pending pair must survive');
    }

    /**
     * The happy path of the commit: pending becomes live in one statement, and the whole
     * pending state - both PEMs, the kid, the relabel intent and the retry stamp - is
     * cleared with it. The SET order is what makes the copy work on MySQL, which
     * evaluates the assignments left to right against already-updated values.
     *
     * @test
     */
    public function committingTheConfirmedKidPromotesThePendingPairAndClearsThePendingState(): void
    {
        $this->createInstallRecord();
        [$pendingPrivateKeyPem, $pendingPublicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->preparePendingIfNone(
            $pendingPrivateKeyPem,
            $pendingPublicKeyPem,
            'confirmed-pending-kid',
            true
        );

        $affectedRows = $this->recordRepository->commitPendingIfKidMatches('confirmed-pending-kid');

        self::assertSame(1, $affectedRows);
        self::assertSame($pendingPrivateKeyPem, $this->readSigningKeyColumn('privatekeypem'));
        self::assertSame($pendingPublicKeyPem, $this->readSigningKeyColumn('publickeypem'));
        self::assertNull($this->readSigningKeyColumn('pendingprivatekeypem'));
        self::assertNull($this->readSigningKeyColumn('pendingpublickeypem'));
        self::assertNull($this->readSigningKeyColumn('pendingkid'));
        self::assertNull($this->readSigningKeyColumn('pendingretriedat'));
        self::assertSame(
            0,
            (int)$this->readSigningKeyColumn('pendingrelabel'),
            'the relabel intent resets to false, never to NULL - the column is NOT NULL'
        );
    }

    /**
     * The SQLite database of this suite would pass the previous test with the SET list in
     * ANY order - it evaluates against the pre-update row, like PostgreSQL. MySQL does not:
     * it assigns left to right, so a reset placed before the copy would put NULL into the
     * live columns. That order therefore has to be pinned on the SQL Doctrine generates,
     * independent of the database the test happens to run on.
     *
     * @test
     */
    public function theCommitStatementCopiesThePendingColumnsBeforeItClearsThem(): void
    {
        $sql = $this->objectManager->get(EntityManagerInterface::class)
            ->createQuery(AgentSigningKeyRecordRepository::COMMIT_PENDING_DQL)
            ->getSQL();

        self::assertLessThan(
            $this->positionOfAssignment($sql, 'pendingprivatekeypem'),
            $this->positionOfAssignment($sql, 'privatekeypem'),
            'privatekeypem must be assigned before pendingprivatekeypem is reset: ' . $sql
        );
        self::assertLessThan(
            $this->positionOfAssignment($sql, 'pendingpublickeypem'),
            $this->positionOfAssignment($sql, 'publickeypem'),
            'publickeypem must be assigned before pendingpublickeypem is reset: ' . $sql
        );
    }

    /**
     * The one non-guarded write: every whitelisted property reaches its column with the
     * right DBAL conversion (booleans as 0/1, dates in the platform format, NULL as NULL),
     * and nothing outside the whitelist - not even a real column - can be written through
     * it. The refusal is checked before any statement runs, so the row stays as it was.
     *
     * @test
     */
    public function updateInstallRowWritesEveryWhitelistedPropertyAndRefusesAnythingElse(): void
    {
        [$livePrivateKeyPem] = $this->createInstallRecord();
        $pushedAt = new DateTimeImmutable('2026-08-17 10:00:00');
        $reenrolledAt = new DateTimeImmutable('2026-08-16 09:30:00');
        $retriedAt = new DateTimeImmutable('2026-08-17 10:05:00');

        $this->recordRepository->updateInstallRow([
            'pendingRelabel' => true,
            'pendingRetriedAt' => $retriedAt,
            'pushKid' => 'pushed-kid',
            'pushTarget' => 'https://api.neosidekick.test',
            'pushStatus' => 'confirmed',
            'pushPluginVersion' => '1.2.3',
            'pushedAt' => $pushedAt,
            'pushInstallRootKid' => 'root-kid',
            'pushReenrolled' => true,
            'pushReenrolledAt' => $reenrolledAt,
            'pushRegisteredDomain' => 'https://www.production.example',
        ]);

        self::assertSame(1, (int)$this->readSigningKeyColumn('pendingrelabel'));
        self::assertSame('2026-08-17 10:05:00', $this->readSigningKeyColumn('pendingretriedat'));
        self::assertSame('pushed-kid', $this->readSigningKeyColumn('pushkid'));
        self::assertSame('https://api.neosidekick.test', $this->readSigningKeyColumn('pushtarget'));
        self::assertSame('confirmed', $this->readSigningKeyColumn('pushstatus'));
        self::assertSame('1.2.3', $this->readSigningKeyColumn('pushpluginversion'));
        self::assertSame('2026-08-17 10:00:00', $this->readSigningKeyColumn('pushedat'));
        self::assertSame('root-kid', $this->readSigningKeyColumn('pushinstallrootkid'));
        self::assertSame(1, (int)$this->readSigningKeyColumn('pushreenrolled'));
        self::assertSame('2026-08-16 09:30:00', $this->readSigningKeyColumn('pushreenrolledat'));
        self::assertSame('https://www.production.example', $this->readSigningKeyColumn('pushregistereddomain'));

        $this->recordRepository->updateInstallRow([
            'pendingRelabel' => false,
            'pendingRetriedAt' => null,
            'pushInstallRootKid' => null,
            'pushReenrolled' => null,
            'pushReenrolledAt' => null,
            'pushRegisteredDomain' => null,
        ]);

        self::assertSame(0, (int)$this->readSigningKeyColumn('pendingrelabel'));
        self::assertNull($this->readSigningKeyColumn('pendingretriedat'));
        self::assertNull($this->readSigningKeyColumn('pushinstallrootkid'));
        self::assertNull($this->readSigningKeyColumn('pushreenrolled'), 'NULL, never false: the column\'s "never written" state');
        self::assertNull($this->readSigningKeyColumn('pushreenrolledat'));
        self::assertNull($this->readSigningKeyColumn('pushregistereddomain'), 'a null echo overwrites the label, it is never carried forward');
        self::assertSame('pushed-kid', $this->readSigningKeyColumn('pushkid'), 'a property not in the call is untouched');

        foreach (['privateKeyPem', 'pendingKid', 'slot', 'pushkid', 'somethingElse'] as $refusedProperty) {
            try {
                $this->recordRepository->updateInstallRow(['pushStatus' => 'revoked', $refusedProperty => 'x']);
                self::fail('Expected a RuntimeException for property ' . $refusedProperty);
            } catch (RuntimeException $exception) {
                self::assertSame(1757000021, $exception->getCode(), $refusedProperty);
            }
        }
        self::assertSame('confirmed', $this->readSigningKeyColumn('pushstatus'), 'a refused call writes nothing at all');
        self::assertSame($livePrivateKeyPem, $this->readSigningKeyColumn('privatekeypem'));
    }

    /**
     * The reason every write in this repository throws a fixed message: the private key
     * is a bound parameter of these statements, and DBAL appends the bound parameters to
     * its exception text - which the module shows to the administrator as a flash
     * message, the CLI prints and the logger writes to disk.
     *
     * The failure provoked here is the one the plan expects in the field: an installation
     * whose migration has not run yet, i.e. a missing table - the one write failure whose
     * message names its remedy.
     *
     * @test
     */
    public function aFailedWriteNeverCarriesThePrivateKeyIntoItsExceptionMessage(): void
    {
        [$pendingPrivateKeyPem, $pendingPublicKeyPem] = $this->generateSigningKeyPair();
        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $classMetadata = [$entityManager->getClassMetadata(AgentSigningKeyRecord::class)];
        $schemaTool->dropSchema($classMetadata);

        $caughtException = null;
        try {
            $this->recordRepository->preparePendingIfNone(
                $pendingPrivateKeyPem,
                $pendingPublicKeyPem,
                'pending-kid',
                false
            );
        } catch (\Throwable $throwable) {
            $caughtException = $throwable;
        } finally {
            $schemaTool->createSchema($classMetadata);
        }

        self::assertInstanceOf(RuntimeException::class, $caughtException, 'a missing table must fail the write');
        self::assertSame(
            'signing-key row write failed: the database migration has not run yet (./flow doctrine:migrate)',
            $caughtException->getMessage()
        );
        self::assertStringNotContainsString('-----BEGIN', $caughtException->getMessage());
        $previousException = $caughtException->getPrevious();
        self::assertNotNull($previousException, 'the driver exception stays available for a stack trace');
        self::assertStringContainsString(
            '-----BEGIN',
            $previousException->getMessage(),
            'the leak this wrapper exists for must be real: DBAL puts the key PEM into its own message'
        );
    }

    /**
     * @return array{0: string, 1: string} The live private and public key PEM
     */
    private function createInstallRecord(): array
    {
        [$privateKeyPem, $publicKeyPem] = $this->generateSigningKeyPair();
        $this->recordRepository->insertIfAbsent(new AgentSigningKeyRecord($privateKeyPem, $publicKeyPem));

        return [$privateKeyPem, $publicKeyPem];
    }

    /**
     * The offset of `<column> =` in the SET list - matched as a whole identifier, because
     * `privatekeypem =` is also the tail of `pendingprivatekeypem =`.
     */
    private function positionOfAssignment(string $sql, string $columnName): int
    {
        self::assertSame(
            1,
            preg_match('/(?<![a-z_])' . preg_quote($columnName, '/') . ' =/', $sql, $matches, PREG_OFFSET_CAPTURE),
            'the statement must assign ' . $columnName . ': ' . $sql
        );

        return (int)$matches[0][1];
    }
}

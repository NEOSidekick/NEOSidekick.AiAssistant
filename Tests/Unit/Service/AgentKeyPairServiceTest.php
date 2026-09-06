<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractDriverException as DriverException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Persistence\Doctrine\Exception\DatabaseStructureException;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * The keypair lives in one database row, so this suite runs the service against the in-memory
 * repository fake, which models the row and the two compare-and-swap predicates.
 *
 * The kid / fingerprint derivation is a cross-repo contract, so it is pinned against a
 * checked-in fixture key with precomputed expected values.
 */
class AgentKeyPairServiceTest extends TestCase
{
    /**
     * Precomputed - do not derive it with the code under test.
     */
    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    private const FIXTURE_FINGERPRINT = '6A:6E:0A:3B:6E:0B:C7:A0:12:7A:00:70:0B:3E:DC:6A:95:2B:72:2C:60:58:4C:E9:59:D5:4E:82:D6:83:C3:34';

    private InMemoryAgentSigningKeyRecordRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryAgentSigningKeyRecordRepository();
    }

    /** @test */
    public function deriveKeyIdMatchesTheSha256OfTheDerEncodedSubjectPublicKeyInfo(): void
    {
        $publicKeyPem = $this->fixturePublicKeyPem();

        self::assertSame(self::FIXTURE_KID, AgentKeyPairService::deriveKeyId($publicKeyPem));
    }

    /** @test */
    public function deriveFingerprintRendersTheSameHashAsUppercaseColonSeparatedBytePairs(): void
    {
        $publicKeyPem = $this->fixturePublicKeyPem();

        self::assertSame(self::FIXTURE_FINGERPRINT, AgentKeyPairService::deriveFingerprint($publicKeyPem));
    }

    /** @test */
    public function deriveKeyIdRejectsANonPemString(): void
    {
        $this->expectException(RuntimeException::class);

        AgentKeyPairService::deriveKeyId('this is not a PEM public key');
    }

    /** @test */
    public function serviceReportsKidAndFingerprintOfTheStoredKeypair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();

        self::assertTrue($service->hasKeyPair());
        self::assertSame(self::FIXTURE_KID, $service->getKeyId());
        self::assertSame(self::FIXTURE_FINGERPRINT, $service->getFingerprint());
        self::assertSame($this->fixturePublicKeyPem(), $service->getPublicKeyPem());
    }

    /**
     * A key is only ever minted from an authenticated push, from a regeneration on a keyless
     * installation or from the CLI: a key nobody pushed cannot mint a usable token, so
     * generating one on a read path would create an inert identity instead of a working one.
     *
     * @test
     */
    public function readingTheKeypairNeverGeneratesOne(): void
    {
        $service = $this->createService();
        self::assertFalse($service->hasKeyPair());

        try {
            $service->getKeyId();
            self::fail('Expected a RuntimeException for the missing keypair');
        } catch (RuntimeException $e) {
            self::assertSame(1755300005, $e->getCode());
        }

        self::assertFalse($service->hasKeyPair(), 'the read must not have written a row');
    }

    /** @test */
    public function generatedKeypairIsRsa2048AndBecomesTheLivePair(): void
    {
        $service = $this->createService();

        $service->generateKeyPair();

        self::assertTrue($service->hasKeyPair());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $service->getKeyId());
        self::assertMatchesRegularExpression('/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/', $service->getFingerprint());
        self::assertSame($service->getKeyId(), AgentKeyPairService::deriveKeyId($service->getPublicKeyPem()));

        $key = openssl_pkey_get_public($service->getPublicKeyPem());
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertSame(2048, $details['bits']);
    }

    /**
     * Replacing a live pair is a regeneration (pending pair, chained push, commit); a plain
     * generation must never overwrite the key the backend trusts.
     *
     * @test
     */
    public function generatingOverAnExistingKeypairIsRefused(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();

        try {
            $service->generateKeyPair();
            self::fail('Expected a RuntimeException for the existing keypair');
        } catch (RuntimeException $e) {
            self::assertSame(1755300001, $e->getCode());
        }

        self::assertSame(self::FIXTURE_KID, $service->getKeyId(), 'the live pair is untouched');
    }

    /**
     * A clobbering second pair would leave the first caller's key diverged from the row and its
     * tokens unverifiable forever.
     *
     * @test
     */
    public function aSecondServiceInstanceLoadsTheExistingKeypairInsteadOfRegeneratingIt(): void
    {
        $firstService = $this->createService();
        $firstService->generateKeyPair();
        $firstKeyId = $firstService->getKeyId();

        $secondService = $this->createService();

        self::assertSame($firstKeyId, $secondService->getKeyId());
        self::assertSame($firstService->getPrivateKeyPem(), $secondService->getPrivateKeyPem());
    }

    /**
     * Two app nodes may generate the first keypair in the same moment. The UNIQUE `slot` column
     * decides it; the loser must adopt the winner's key rather than fail or overwrite, because
     * the winner's key is from then on this installation's identity.
     *
     * @test
     */
    public function losingTheInsertRaceAdoptsTheWinnersKeypair(): void
    {
        $repository = new class () extends InMemoryAgentSigningKeyRecordRepository {
            public ?AgentSigningKeyRecord $winner = null;

            /**
             * The other node commits its INSERT between our "no key yet" check and our own.
             */
            public function insertIfAbsent(AgentSigningKeyRecord $record): bool
            {
                if ($this->winner !== null) {
                    $this->seed($this->winner);
                    $this->winner = null;
                }

                return parent::insertIfAbsent($record);
            }
        };
        $repository->winner = new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem());
        $service = $this->createService($repository);

        $service->generateKeyPair();

        self::assertSame(self::FIXTURE_KID, $service->getKeyId(), 'the winner\'s key is the installation key');
        self::assertSame($this->fixturePrivateKeyPem(), $service->getPrivateKeyPem());
    }

    /** @test */
    public function anUnparseablePublicKeyColumnIsAnErrorAndIsNeverCached(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $this->setColumn($this->repository->findInstallRecord(), 'publicKeyPem', 'not a pem at all');

        try {
            $service->getPublicKeyPem();
            self::fail('Expected a RuntimeException for the unparseable public key');
        } catch (RuntimeException $e) {
            self::assertSame(1755300012, $e->getCode());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1755300012);
        $service->getKeyId();
    }

    /**
     * A private key and a public key of different keys would sign tokens the backend verifies
     * against the wrong key - the pair is refused instead of served, like a corrupt one.
     *
     * @test
     */
    public function aPrivateKeyAndAPublicKeyOfDifferentKeysAreRefusedAsASplitPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $this->setColumn(
            $this->repository->findInstallRecord(),
            'publicKeyPem',
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem')
        );

        self::assertTrue($service->hasKeyPair(), 'the row exists, so nothing is generated');
        try {
            $service->getKeyId();
            self::fail('Expected a RuntimeException for the split pair');
        } catch (RuntimeException $e) {
            self::assertSame(1755300016, $e->getCode());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1755300016);
        $service->getPrivateKeyPem();
    }

    /**
     * The panel and the regeneration guard need the same validation as an ANSWER, not as a
     * failure - and, like every read, without minting a key on the way. Only a row that cannot
     * be read at all stays an exception: a dropped connection must never read as a corrupt pair.
     *
     * @test
     */
    public function theLivePairIsAlsoValidatedWithoutThrowingAndWithoutGeneratingOne(): void
    {
        $keyless = $this->createService();
        self::assertFalse($keyless->isLiveKeyPairUsable());
        self::assertNull($this->repository->findInstallRecord(), 'asking must not mint a key');

        $service = $this->createServiceWithFixtureKeyPair();
        self::assertTrue($service->isLiveKeyPairUsable());

        $this->setColumn(
            $this->repository->findInstallRecord(),
            'publicKeyPem',
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem')
        );
        self::assertFalse($this->createService()->isLiveKeyPairUsable(), 'a split pair is not usable');

        $this->repository->failLoads(new RuntimeException('MySQL server has gone away'));
        $this->expectException(AgentSigningKeyStorageException::class);
        $this->createService()->isLiveKeyPairUsable();
    }

    /**
     * The migration may not have run yet. Every entry point asks this question first, so a
     * missing table must read as "no key" instead of failing the Configuration module and the
     * CLI outright. The read goes through Flow's Query, which rewraps the driver's exception
     * into its own DatabaseStructureException WITHOUT chaining it - so that unchained shape is
     * what the service must recognize, and what the push paths then ask about before they
     * would generate a first key into a table that is not there.
     *
     * @test
     */
    public function aMissingSigningKeyTableIsReportedAsNoKeypair(): void
    {
        $service = $this->createService();
        self::assertFalse($service->isStorageMissingTable());
        $this->repository->failLoads($this->missingTableOnRead());

        self::assertFalse($service->hasKeyPair());
        self::assertTrue($service->isStorageMissingTable());

        $this->repository->failLoads(null);
        self::assertFalse($service->hasKeyPair());
        self::assertFalse($service->isStorageMissingTable(), 'an existing table without a row is not a missing table');
    }

    /**
     * The write path sees the driver's own exception (nothing rewraps it there), so it is the
     * one place that can name the remedy - and, like every other write failure, it must not
     * carry the bound PEM the driver puts into its message.
     *
     * @test
     */
    public function aWriteIntoTheMissingTableNamesTheMigrationWithoutAnyKeyMaterial(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willThrowException(new TableNotFoundException(
            'An exception occurred while executing \'INSERT INTO ...\' with params ["-----BEGIN PRIVATE KEY-----"]: Base table or view not found',
            new DriverException('Table \'...agentsigningkeyrecord\' doesn\'t exist', '42S02', 1146)
        ));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $repository = new AgentSigningKeyRecordRepository();
        $this->injectProperty($repository, 'entityManager', $entityManager);

        try {
            $repository->insertIfAbsent(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));
            self::fail('Expected a RuntimeException for the missing table');
        } catch (RuntimeException $e) {
            self::assertSame(
                'signing-key row write failed: the database migration has not run yet (./flow doctrine:migrate)',
                $e->getMessage()
            );
            self::assertStringNotContainsString('-----BEGIN', $e->getMessage());
            self::assertInstanceOf(TableNotFoundException::class, $e->getPrevious(), 'the driver exception stays available for a stack trace');
        }
    }

    /**
     * A row that cannot be READ is not "there is no key": on the verification path the first
     * answer must never de-authorize an editor over a dropped connection, so it is a distinct
     * exception type rather than the missing-keypair error.
     *
     * @test
     */
    public function aFailedLoadIsAStorageErrorRatherThanAMissingKeypair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $this->repository->failLoads(new RuntimeException('MySQL server has gone away'));

        try {
            $service->hasKeyPair();
            self::fail('Expected an AgentSigningKeyStorageException');
        } catch (AgentSigningKeyStorageException $e) {
            self::assertSame(1757000012, $e->getCode());
            self::assertNotSame(1755300005, $e->getCode(), 'a failed load must not read as "no keypair"');
        }

        $this->expectException(AgentSigningKeyStorageException::class);
        $service->getKeyId();
    }

    /**
     * Every retry of an unconfirmed regeneration must transmit the SAME pending key, so preparing
     * it twice must not create a third one - and the live pair must not be touched at all.
     *
     * @test
     */
    public function preparingThePendingPairIsIdempotentAndLeavesTheLivePairUntouched(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        self::assertFalse($service->hasPendingKeyPair());

        $service->preparePendingKeyPair();
        self::assertTrue($service->hasPendingKeyPair());
        $pendingPublicKeyPem = $service->getPendingPublicKeyPem();

        $service->preparePendingKeyPair();

        self::assertSame($pendingPublicKeyPem, $service->getPendingPublicKeyPem());
        self::assertNotSame(self::FIXTURE_KID, AgentKeyPairService::deriveKeyId($pendingPublicKeyPem));
        self::assertSame(self::FIXTURE_KID, $service->getKeyId(), 'the live pair is what the plugin keeps signing with');
        self::assertSame($this->fixturePublicKeyPem(), $service->getPublicKeyPem());
    }

    /** @test */
    public function committingThePendingPairPromotesItAndServesTheNewLiveKeys(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        self::assertSame(self::FIXTURE_KID, $service->getKeyId(), 'read the live pair first');
        $service->preparePendingKeyPair();
        $pendingPublicKeyPem = $service->getPendingPublicKeyPem();
        $pendingPrivateKeyPem = (string)$this->repository->findInstallRecord()?->getPendingPrivateKeyPem();

        self::assertTrue($service->commitPendingKeyPair(AgentKeyPairService::deriveKeyId($pendingPublicKeyPem)));

        self::assertFalse($service->hasPendingKeyPair());
        self::assertNull($this->repository->findInstallRecord()?->getPendingKid());
        self::assertSame($pendingPublicKeyPem, $service->getPublicKeyPem());
        self::assertSame($pendingPrivateKeyPem, $service->getPrivateKeyPem());
        self::assertSame(AgentKeyPairService::deriveKeyId($pendingPublicKeyPem), $service->getKeyId());
    }

    /**
     * Without a pending pair there is nothing the confirmation can name, and the live pair is
     * not the confirmed key either - so the confirmation belongs to a key this installation
     * does not have, which is a visible failure and never a silent success.
     *
     * @test
     */
    public function committingWithoutAPendingPairIsAnError(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1756800004);

        $service->commitPendingKeyPair('some-other-kid');
    }

    /**
     * A concurrent push of the same pending key may have promoted it already; the late caller
     * must neither fail nor be told it promoted anything.
     *
     * @test
     */
    public function committingTheKeyThatAlreadyIsTheLivePairIsANoOp(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();

        self::assertFalse($service->commitPendingKeyPair(self::FIXTURE_KID));
        self::assertSame(self::FIXTURE_KID, $service->getKeyId());
    }

    /**
     * Rotation A transmits P, a concurrent push commits P, a new regeneration prepares Q - A's
     * late confirmation of P must not promote Q, a key the backend never confirmed.
     *
     * @test
     */
    public function committingUnderTheKidOfAnotherKeyLeavesBothPairsUntouched(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $service->preparePendingKeyPair();
        $pendingPublicKeyPem = $service->getPendingPublicKeyPem();

        try {
            $service->commitPendingKeyPair('kid-of-a-key-the-backend-confirmed-earlier');
            self::fail('Expected a RuntimeException for the mismatching pending key');
        } catch (RuntimeException $e) {
            self::assertSame(1756800004, $e->getCode());
        }

        self::assertTrue($service->hasPendingKeyPair());
        self::assertSame($pendingPublicKeyPem, $service->getPendingPublicKeyPem());
        self::assertSame(self::FIXTURE_KID, $service->getKeyId());
    }

    /**
     * The relabel flag belongs to the pending key: a retry asking for it sets it, a retry without
     * it keeps it, the commit removes it, and a fresh pending pair starts without it.
     *
     * @test
     */
    public function theRelabelFlagSticksToThePendingPairUntilItIsCommitted(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        self::assertFalse($service->isPendingRelabel());

        $service->preparePendingKeyPair();
        self::assertFalse($service->isPendingRelabel());
        $service->preparePendingKeyPair(true);
        self::assertTrue($service->isPendingRelabel());
        $service->preparePendingKeyPair();
        self::assertTrue($service->isPendingRelabel(), 'a plain retry keeps the flag');

        $service->commitPendingKeyPair(AgentKeyPairService::deriveKeyId($service->getPendingPublicKeyPem()));
        self::assertFalse($service->isPendingRelabel());
        self::assertFalse((bool)$this->repository->findInstallRecord()?->isPendingRelabel());

        $this->setColumn($this->repository->findInstallRecord(), 'pendingRelabel', true);
        self::assertFalse($service->isPendingRelabel(), 'a flag without a pending pair means nothing');
        $service->preparePendingKeyPair();
        self::assertFalse($service->isPendingRelabel(), 'a fresh pending pair drops the leftover flag');
    }

    /**
     * A re-enrolment or a relabel must never announce a successor it did not mint: on a copy of
     * another installation the waiting pair can be the original's. With $forceFresh the pending
     * slot is swapped in one statement - the pair stays "pending" throughout, so a concurrent
     * authorization never falls back onto the live key - and the swap OWNS the relabel flag in
     * both directions, which {@see stickRelabelFlag} could not do (it returns early on false).
     *
     * @test
     */
    public function aForcedPreparationReplacesTheWaitingSuccessorAndOwnsTheRelabelFlagInBothDirections(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $service->preparePendingKeyPair();
        $inheritedKid = $this->repository->findInstallRecord()?->getPendingKid();
        self::assertNotNull($inheritedKid);
        self::assertFalse($service->isPendingRelabel());

        $service->preparePendingKeyPair(true, true);

        $relabelledKid = $this->repository->findInstallRecord()?->getPendingKid();
        self::assertTrue($service->hasPendingKeyPair(), 'the swap never leaves the row without a successor');
        self::assertNotSame($inheritedKid, $relabelledKid, 'the inherited successor was replaced');
        self::assertSame(
            $relabelledKid,
            AgentKeyPairService::deriveKeyId($service->getPendingPublicKeyPem()),
            'the stored kid is the one of the stored public key'
        );
        self::assertTrue($service->isPendingRelabel());
        self::assertNull($service->getPendingKeyPairRetriedAt(), 'a fresh successor is never delayed');

        $service->preparePendingKeyPair(false, true);

        self::assertNotSame($relabelledKid, $this->repository->findInstallRecord()?->getPendingKid());
        self::assertFalse($service->isPendingRelabel(), 'the replacement never inherits the relabel intent');
    }

    /**
     * A concurrent regeneration replacing or committing the successor between the read and the
     * swap is not an error: the winner's pair is reused, and the loser's intent is written onto
     * it in both directions - the same ownership the swap itself would have had.
     *
     * @test
     */
    public function aLostReplacementRaceReusesTheWinnersSuccessorAndWritesTheLosersIntentInBothDirections(): void
    {
        $repository = $this->repositoryLosingEveryReplacement();
        $service = $this->createService($repository);
        $service->preparePendingKeyPair();
        $winnersKid = $repository->findInstallRecord()?->getPendingKid();

        $service->preparePendingKeyPair(true, true);

        self::assertSame($winnersKid, $repository->findInstallRecord()?->getPendingKid());
        self::assertTrue($service->isPendingRelabel(), 'the relabel intent falls back onto the winner\'s pair');
    }

    /**
     * The other direction of the same fallback: a re-enrolment that loses the swap must not
     * announce the winner's pair under a relabel intent it never asked for.
     *
     * @test
     */
    public function aLostReplacementRaceOnAReenrolmentClearsTheWinnersRelabelFlag(): void
    {
        $repository = $this->repositoryLosingEveryReplacement();
        $service = $this->createService($repository);
        $service->preparePendingKeyPair(true);
        $winnersKid = $repository->findInstallRecord()?->getPendingKid();
        self::assertTrue($service->isPendingRelabel());

        $service->preparePendingKeyPair(false, true);

        self::assertSame($winnersKid, $repository->findInstallRecord()?->getPendingKid());
        self::assertFalse($service->isPendingRelabel(), 'the re-enrolment drops the intent the winner\'s pair carried');
    }

    /**
     * A repository whose compare-and-swap always loses, i.e. a concurrent regeneration replaced
     * or committed the waiting successor between the read and the swap.
     */
    private function repositoryLosingEveryReplacement(): InMemoryAgentSigningKeyRecordRepository
    {
        $repository = new class () extends InMemoryAgentSigningKeyRecordRepository {
            public function replacePendingIfKidMatches(
                string $expectedPendingKid,
                string $privateKeyPem,
                string $publicKeyPem,
                string $newKid,
                bool $relabel
            ): int {
                return 0;
            }
        };
        $repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));

        return $repository;
    }

    /**
     * A relabel whose push failed must not be completed later by the automatic authorization
     * push, so the intent is dropped while the successor itself stays. The write goes past the
     * identity map, so the managed row has to be refreshed with it - pinned here by requiring the
     * refresh on the EntityManager the service holds.
     *
     * @test
     */
    public function clearPendingRelabelDropsTheIntentAndRefreshesTheManagedRow(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->expects(self::atLeastOnce())->method('refresh')->with(self::isInstanceOf(AgentSigningKeyRecord::class));
        $this->repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));
        $service = new AgentKeyPairService();
        $this->injectProperty($service, 'agentSigningKeyRecordRepository', $this->repository);
        $this->injectProperty($service, 'entityManager', $entityManager);
        $service->preparePendingKeyPair(true);
        $pendingKid = $this->repository->findInstallRecord()?->getPendingKid();

        $service->clearPendingRelabel();

        self::assertFalse($service->isPendingRelabel());
        self::assertFalse((bool)$this->repository->findInstallRecord()?->isPendingRelabel());
        self::assertSame($pendingKid, $this->repository->findInstallRecord()?->getPendingKid(), 'the successor itself stays');
    }

    /** @test */
    public function clearPendingRelabelOnAKeylessInstallationDoesNothing(): void
    {
        $service = $this->createService();

        $service->clearPendingRelabel();

        self::assertFalse($service->hasKeyPair());
    }

    /** @test */
    public function thePendingPairRecordsWhenItWasLastRetried(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        self::assertNull($service->getPendingKeyPairRetriedAt());
        self::assertFalse($service->markPendingKeyPairRetried());

        $service->preparePendingKeyPair();
        self::assertNull($service->getPendingKeyPairRetriedAt(), 'a fresh pending pair is never delayed');
        $this->setColumn($this->repository->findInstallRecord(), 'pendingRetriedAt', new DateTimeImmutable('@' . (time() - 600)));
        self::assertLessThanOrEqual(time() - 600, (int)$service->getPendingKeyPairRetriedAt());

        self::assertTrue($service->markPendingKeyPairRetried());
        self::assertGreaterThanOrEqual(time() - 5, (int)$service->getPendingKeyPairRetriedAt());
    }

    /**
     * All three pending columns belong together; a half-written successor is no successor, and
     * asking for its public key is an error rather than a null the callers would push.
     *
     * @test
     */
    public function aLonePendingColumnIsNotAPendingPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $this->setColumn(
            $this->repository->findInstallRecord(),
            'pendingPublicKeyPem',
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem')
        );

        self::assertFalse($service->hasPendingKeyPair());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1756800001);
        $service->getPendingPublicKeyPem();
    }

    /**
     * The pending pair signs its own host set on the rotation push, so its private half is
     * served next to its public one - and only while all three pending columns are set.
     *
     * @test
     */
    public function thePendingPrivateKeyIsServedWithThePendingPairAndBelongsToItsPublicKey(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();
        $service->preparePendingKeyPair();

        $pendingPrivateKeyPem = $service->getPendingPrivateKeyPem();

        self::assertStringContainsString('PRIVATE KEY', $pendingPrivateKeyPem);
        self::assertNotSame($this->fixturePrivateKeyPem(), $pendingPrivateKeyPem, 'the live private key is not the pending one');
        $details = openssl_pkey_get_details(openssl_pkey_get_private($pendingPrivateKeyPem));
        self::assertIsArray($details);
        self::assertSame(
            AgentKeyPairService::deriveKeyId($service->getPendingPublicKeyPem()),
            AgentKeyPairService::deriveKeyId((string)$details['key']),
            'the served private key is the one of the pending public key'
        );
    }

    /** @test */
    public function thePendingPrivateKeyIsAnErrorWithoutAPendingPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1757100001);
        $service->getPendingPrivateKeyPem();
    }

    private function createService(?AgentSigningKeyRecordRepository $repository = null): AgentKeyPairService
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);

        $service = new AgentKeyPairService();
        $this->injectProperty($service, 'agentSigningKeyRecordRepository', $repository ?? $this->repository);
        $this->injectProperty($service, 'entityManager', $entityManager);

        return $service;
    }

    private function createServiceWithFixtureKeyPair(): AgentKeyPairService
    {
        $this->repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));

        return $this->createService();
    }

    /**
     * What Flow's Query throws for a missing table: its own exception, with the DBAL message
     * text and code but WITHOUT the driver exception chained.
     */
    private function missingTableOnRead(): DatabaseStructureException
    {
        return new DatabaseStructureException('A table or view seems to be missing from the database.', 1146);
    }

    private function fixturePublicKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem');
    }

    private function fixturePrivateKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pem');
    }

    /**
     * The entity has no setters: only the database writes its columns.
     */
    private function setColumn(?AgentSigningKeyRecord $record, string $propertyName, mixed $value): void
    {
        self::assertNotNull($record);
        $this->injectProperty($record, $propertyName, $value);
    }

    private function injectProperty(object $target, string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty($target::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}

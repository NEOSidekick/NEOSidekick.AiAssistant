<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use NEOSidekick\AiAssistant\Command\AgentKeyCommandController;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * The CLI is a thin front for the shared regeneration
 * (AgentSigningKeyPushService::rotateKeyPair()): `agentkey:generate --force` delegates to
 * it instead of overwriting the live key itself, exits non-zero whenever NEOSidekick did not
 * confirm the key, and both `generate` and `push` warn first of all when a regeneration is
 * still waiting for NEOSidekick's confirmation - the pending pair is the key NEOSidekick may
 * already have accepted.
 *
 * The keypair lives in one database row, driven here by
 * {@see InMemoryAgentSigningKeyRecordRepository} so the suite stays a unit test.
 */
class AgentKeyCommandControllerTest extends TestCase
{
    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    private InMemoryAgentSigningKeyRecordRepository $repository;

    private AgentKeyPairService $agentKeyPairService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryAgentSigningKeyRecordRepository();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);

        $this->agentKeyPairService = new AgentKeyPairService();
        $this->setProtectedProperty($this->agentKeyPairService, 'agentSigningKeyRecordRepository', $this->repository);
        $this->setProtectedProperty($this->agentKeyPairService, 'entityManager', $entityManager);
        $this->setProtectedProperty($this->agentKeyPairService, 'logger', $this->createMock(LoggerInterface::class));
    }

    /** @test */
    public function forcedGenerationRunsTheSharedRotationAndPrintsTheOutcome(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService);

        $controller->generateCommand(true);

        self::assertSame(1, $pushService->rotations, 'the CLI must not overwrite the live key itself');
        self::assertFalse($pushService->wasPushed);
        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('confirmed', $output);
        self::assertStringContainsString('root-kid', $output);
        self::assertStringContainsString('Regenerate key', $output, 'the module button is mentioned');
    }

    /** @test */
    public function nonForcedGenerationRefusesWhenALivePairExists(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB'));
        $controller = $this->createController($pushService);

        try {
            $controller->generateCommand();
            self::fail('Expected the command to quit');
        } catch (RuntimeException $exception) {
            self::assertSame(1755400001, $exception->getCode());
        }

        self::assertSame(0, $pushService->rotations);
        self::assertStringContainsString('--force', $this->joinedOutput($controller));
    }

    /** @test */
    public function aFirstGenerationRunsTheSharedRotationToo(): void
    {
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('pending', 'new-kid', 'AA:BB'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand());

        self::assertSame(1, $pushService->rotations);
        self::assertTrue($this->agentKeyPairService->hasKeyPair());
        self::assertStringContainsString('pending', $this->joinedOutput($controller));
    }

    /**
     * A first generation whose push failed keeps the generated live pair - it is enrolled by the
     * next push - but the command still exits non-zero: the key is not registered.
     *
     * @test
     */
    public function aFailedPushAfterAFirstGenerationKeepsTheKeyButExitsNonZero(): void
    {
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::failure('backend unreachable'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand());

        self::assertTrue($this->agentKeyPairService->hasKeyPair());
        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('Generated a new RSA 2048', $output);
        self::assertStringContainsString('backend unreachable', $output);
        self::assertStringContainsString('agentkey:push', $output);
    }

    /** @test */
    public function aFailedRotationLeavesThePendingPairTellsTheOperatorToReRunForTheSameKeyAndExitsNonZero(): void
    {
        $this->seedFixtureKeyPair();
        $this->seedPendingKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::failure('The NEOSidekick backend rejected the signing key with status 503: maintenance', 'new-kid'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand(true));

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('status 503', $output);
        self::assertStringContainsString('Nothing has changed', $output);
        self::assertStringContainsString('SAME pending key', $output);
        self::assertStringContainsString('Regenerate key', $output);
        self::assertStringNotContainsString('Generated', $output);
        self::assertTrue($this->agentKeyPairService->hasPendingKeyPair());
    }

    /**
     * A rotation that failed before a pending pair existed (the successor could not even be
     * written) has nothing to report but the error - no key details, no "generated".
     *
     * @test
     */
    public function aRotationThatFailedBeforeAPendingPairExistedPrintsOnlyTheError(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::failure('The signing key could not be regenerated: signing-key row write failed'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand(true));

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('The key was not regenerated: The signing key could not be regenerated: signing-key row write failed', $output);
        self::assertStringNotContainsString('Generated', $output);
        self::assertStringNotContainsString('Key id (kid)', $output);
        self::assertStringNotContainsString('agentkey:push', $output);
    }

    /** @test */
    public function aConvergingReRunReportsThePendingKeyAsTransmittedInsteadOfGenerated(): void
    {
        $this->seedFixtureKeyPair();
        $this->seedPendingKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService);

        $controller->generateCommand(true);

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('Transmitted the pending key', $output);
        self::assertStringNotContainsString('Generated a new', $output);
    }

    /** @test */
    public function relabelIsForwardedToTheRotationAndRequiresForce(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService);

        $controller->generateCommand(true, null, true);
        self::assertTrue($pushService->recordedRelabel);

        $refusingController = $this->createController($pushService);
        $this->assertQuitsWithExitCode1(fn () => $refusingController->generateCommand(false, null, true));
        self::assertSame(1, $pushService->rotations, 'refused before any rotation');
        self::assertStringContainsString('--force --relabel', $this->joinedOutput($refusingController));
    }

    /**
     * Relabelling a database copy (a staging site restored from a production dump) skips the
     * backend's clone rule and revokes production's live key, so the help text of the flag - the
     * only place an operator reads before using it - must warn about it and name the remedy.
     *
     * @test
     */
    public function theRelabelHelpTextWarnsAgainstRunningItOnACopyOfAnotherInstallationsDatabase(): void
    {
        $helpText = (string)(new ReflectionMethod(AgentKeyCommandController::class, 'generateCommand'))->getDocComment();

        self::assertStringContainsString('Never run --relabel on a copy of another installation', $helpText);
        self::assertStringContainsString('tick the re-enrolment checkbox on the regeneration form', $helpText, 'and names the remedy the module offers');
    }

    /**
     * A regeneration is no longer the way out of a split pair - it would enrol the installation
     * anew - so the advice names the backup restore first and the two explicit re-enrolment
     * paths after it.
     *
     * @test
     */
    public function showRefusesAStoredPairWhoseHalvesDoNotMatchAndPointsAtTheRestoreAndTheReenrolmentPaths(): void
    {
        $this->seedFixtureKeyPair();
        $this->setRecordColumn('publicKeyPem', $this->rotatedPublicKeyPem());
        $controller = $this->createController($this->createRecordingPushService(AgentSigningKeyPushResult::failure('unused')));

        $this->assertQuitsWithExitCode1(fn () => $controller->showCommand());

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('The stored agent signing keypair is unusable', $output);
        self::assertStringContainsString('Restore the signing-key row from a backup', $output);
        self::assertStringContainsString('re-enrolment checkbox', $output);
        self::assertStringContainsString('DELETE FROM neosidekick_aiassistant_domain_model_agentsigningkeyrecord', $output);
        self::assertStringNotContainsString('agentkey:generate --force', $output, 'a regeneration cannot chain to an unusable key');
    }

    /**
     * The abort is the CLI's half of MH3: --force on an unusable pair must not enrol this
     * installation anew, and it must say what the two explicit paths are.
     *
     * @test
     */
    public function forcedGenerationOnAnUnusableStoredPairAbortsAndNamesTheTwoReenrolmentPaths(): void
    {
        $this->seedFixtureKeyPair();
        $this->setRecordColumn('publicKeyPem', $this->rotatedPublicKeyPem());
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand(true));

        /** @phpstan-ignore-next-line the test double counts the rotations */
        self::assertSame(0, $pushService->rotations, 'nothing was rotated');
        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('the stored keypair is unusable', $output);
        self::assertStringContainsString('Restore the signing-key row from a backup', $output);
        self::assertStringContainsString('re-enrolment checkbox on the regeneration form', $output);
        self::assertStringContainsString('DELETE FROM neosidekick_aiassistant_domain_model_agentsigningkeyrecord', $output);
        self::assertStringContainsString('./flow agentkey:generate', $output);
    }

    /**
     * @test
     */
    public function anIncompleteRegenerationIsAnnouncedAsTheFirstLineOfGenerateAndPush(): void
    {
        $this->seedFixtureKeyPair();
        $this->seedPendingKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', 'new-kid', 'AA:BB'));

        $generateController = $this->createController($pushService);
        try {
            $generateController->generateCommand();
        } catch (RuntimeException $exception) {
            // quits because a live pair exists and --force was not given
        }
        /** @phpstan-ignore-next-line the test double exposes the recorded output */
        $firstGenerateLine = $generateController->recordedOutput[0];
        self::assertStringContainsString('incomplete', $firstGenerateLine);
        self::assertStringContainsString('SAME pending key', $firstGenerateLine);
        self::assertStringNotContainsString('.pending', $firstGenerateLine, 'there are no key files to preserve any more');

        $pushController = $this->createController($pushService);
        $pushController->pushCommand();
        /** @phpstan-ignore-next-line the test double exposes the recorded output */
        self::assertStringContainsString('SAME pending key', $pushController->recordedOutput[0]);
        self::assertTrue($pushService->wasPushed, 'push transmits (the pending key - the service decides that)');
    }

    /** @test */
    public function nothingIsAnnouncedWhenNoRegenerationIsPending(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('confirmed', self::FIXTURE_KID, 'AA:BB'));
        $controller = $this->createController($pushService);

        $controller->pushCommand();

        self::assertStringNotContainsString('incomplete', $this->joinedOutput($controller));
    }

    /** @test */
    public function pushCommandPrintsTheStatusAndTheFingerprintOfTheKey(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(
            AgentSigningKeyPushResult::success('pending', self::FIXTURE_KID, '6A:6E:0A:3B')
        );
        $controller = $this->createController($pushService);

        $controller->pushCommand();

        self::assertTrue($pushService->wasPushed);
        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('pending', $output);
        self::assertStringContainsString('6A:6E:0A:3B', $output);
    }

    /**
     * On the CLI the site domain is often unresolvable (no active request, no configured
     * baseUri) and the push service refuses to register a guessed one - so both commands
     * must forward the operator's explicit --domain.
     *
     * @test
     */
    public function theDomainOverrideIsForwardedToThePushService(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('pending', self::FIXTURE_KID, '6A:6E:0A:3B'));
        $controller = $this->createController($pushService);

        $controller->pushCommand('https://www.example.com');

        self::assertSame('https://www.example.com', $pushService->recordedDomainOverride);
    }

    /** @test */
    public function theDomainOverrideIsForwardedFromTheGenerateCommandToo(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('pending', 'new-kid', 'AA:BB'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand(true, 'https://www.example.com'));

        self::assertSame('https://www.example.com', $pushService->recordedDomainOverride);
    }

    /** @test */
    public function aRegenerationThatComesBackRevokedExitsNonZeroAndPointsAtAnotherRegeneration(): void
    {
        $this->seedFixtureKeyPair();
        $this->seedPendingKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('revoked', 'new-kid', 'AA:BB', 'root-kid'));
        $controller = $this->createController($pushService);

        $this->assertQuitsWithExitCode1(fn () => $controller->generateCommand(true));

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('revoked', $output);
        self::assertStringContainsString('agentkey:generate --force', $output);
        self::assertStringNotContainsString('agentkey:push', $output, 'pushing again cannot change a recorded status');
        self::assertStringNotContainsString('<success>', $output, 'nothing about a non-confirmed outcome is a success');
    }

    /**
     * An exit 1 here would be a new contract for the provisioning scripts that run this command.
     *
     * @test
     */
    public function pushCommandStillExitsZeroWhenTheKeyComesBackRevoked(): void
    {
        $this->seedFixtureKeyPair();
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('revoked', self::FIXTURE_KID, '6A:6E:0A:3B'));
        $controller = $this->createController($pushService);

        $controller->pushCommand();

        $output = $this->joinedOutput($controller);
        self::assertStringContainsString('revoked', $output);
        self::assertStringContainsString('agentkey:generate --force', $output);
    }

    /** @test */
    public function pushCommandRefusesWhenNoKeypairExistsYet(): void
    {
        $pushService = $this->createRecordingPushService(AgentSigningKeyPushResult::success('pending', 'kid', null));
        $controller = $this->createController($pushService);

        $this->expectException(RuntimeException::class);

        try {
            $controller->pushCommand();
        } finally {
            self::assertFalse($pushService->wasPushed);
        }
    }

    private function createController(AgentSigningKeyPushService $pushService): AgentKeyCommandController
    {
        $controller = new class () extends AgentKeyCommandController {
            /**
             * @var array<int, string>
             */
            public array $recordedOutput = [];

            protected function outputLine(string $text = '', array $arguments = [])
            {
                $this->recordedOutput[] = $arguments === [] ? $text : vsprintf($text, $arguments);
            }

            protected function quit(int $exitCode = 0)
            {
                throw new RuntimeException('quit with exit code ' . $exitCode, 1755400001);
            }
        };

        $this->setProtectedProperty($controller, 'agentKeyPairService', $this->agentKeyPairService);
        $this->setProtectedProperty($controller, 'agentSigningKeyPushService', $pushService);

        return $controller;
    }

    /**
     * Records the calls; a rotation on an installation without a row creates the live pair the
     * real one would, so the command can print its details.
     */
    private function createRecordingPushService(AgentSigningKeyPushResult $result): AgentSigningKeyPushService
    {
        return new class ($result, $this->agentKeyPairService) extends AgentSigningKeyPushService {
            public bool $wasPushed = false;

            public int $rotations = 0;

            public ?string $recordedDomainOverride = null;

            public bool $recordedRelabel = false;

            public function __construct(private readonly AgentSigningKeyPushResult $result, private readonly AgentKeyPairService $keyPairService)
            {
            }

            public function rotateKeyPair(?string $domainOverride = null, bool $relabel = false, bool $allowReenrolment = false): AgentSigningKeyPushResult
            {
                $this->rotations++;
                $this->recordedDomainOverride = $domainOverride;
                $this->recordedRelabel = $relabel;
                if (!$this->keyPairService->hasKeyPair()) {
                    $this->keyPairService->generateKeyPair();
                }

                return $this->result;
            }

            public function push(?string $chainKid = null, ?string $chainSignature = null, ?string $domainOverride = null): AgentSigningKeyPushResult
            {
                $this->wasPushed = true;
                $this->recordedDomainOverride = $domainOverride;

                return $this->result;
            }
        };
    }

    /**
     * The test double turns quit() into an exception carrying the exit code.
     */
    private function assertQuitsWithExitCode1(callable $command): void
    {
        try {
            $command();
            self::fail('Expected the command to quit');
        } catch (RuntimeException $exception) {
            self::assertSame(1755400001, $exception->getCode());
            self::assertSame('quit with exit code 1', $exception->getMessage());
        }
    }

    private function joinedOutput(AgentKeyCommandController $controller): string
    {
        /** @phpstan-ignore-next-line the test double exposes the recorded output */
        return implode("\n", $controller->recordedOutput);
    }

    private function seedFixtureKeyPair(): void
    {
        $this->repository->seed(new AgentSigningKeyRecord(
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pem'),
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem')
        ));
    }

    /**
     * A regeneration waiting for NEOSidekick's confirmation, written straight into the pending
     * columns - the compare-and-swap that normally does it is the repository's business.
     */
    private function seedPendingKeyPair(): void
    {
        $publicKeyPem = $this->rotatedPublicKeyPem();
        $this->setRecordColumn('pendingPrivateKeyPem', (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pem'));
        $this->setRecordColumn('pendingPublicKeyPem', $publicKeyPem);
        $this->setRecordColumn('pendingKid', AgentKeyPairService::deriveKeyId($publicKeyPem));
    }

    private function setRecordColumn(string $propertyName, ?string $value): void
    {
        $record = $this->repository->findInstallRecord();
        self::assertNotNull($record, 'the installation has no signing-key row');

        $property = new ReflectionProperty(AgentSigningKeyRecord::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($record, $value);
    }

    private function rotatedPublicKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem');
    }

    private function setProtectedProperty(object $object, string $propertyName, mixed $value): void
    {
        $className = get_class($object);
        if (!(new \ReflectionClass($className))->hasProperty($propertyName)) {
            $className = (string)get_parent_class($object);
        }
        $property = new ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}

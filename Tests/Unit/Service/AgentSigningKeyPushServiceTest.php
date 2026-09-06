<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use NEOSidekick\AiAssistant\Domain\Model\AgentSigningKeyRecord;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentSigningKeyStorageException;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Unit\Fixtures\InMemoryAgentSigningKeyRecordRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The key push is a cross-repo contract - kid, domain label and the chain signature over the
 * TRIMMED PEM - pinned here together with the two properties the calling flows depend on: the
 * push happens once per key and target, and it never throws.
 *
 * Key and push state are one database row; this suite stays a unit test by driving that row
 * through {@see InMemoryAgentSigningKeyRecordRepository}, which models the two compare-and-swap
 * predicates the rotation protocol rests on.
 */
class AgentSigningKeyPushServiceTest extends TestCase
{
    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    private const FIXTURE_FINGERPRINT = '6A:6E:0A:3B:6E:0B:C7:A0:12:7A:00:70:0B:3E:DC:6A:95:2B:72:2C:60:58:4C:E9:59:D5:4E:82:D6:83:C3:34';

    private InMemoryAgentSigningKeyRecordRepository $repository;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $requestHistory = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new InMemoryAgentSigningKeyRecordRepository();
        $this->requestHistory = [];
    }

    /** @test */
    public function pushSendsTheKeyMaterialTheSiteDomainAndThePluginVersionToTheSigningKeyEndpoint(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()]);

        $result = $service->push();

        self::assertTrue($result->successful);
        self::assertSame('pending', $result->status);
        self::assertSame(self::FIXTURE_KID, $result->keyId);
        self::assertSame(self::FIXTURE_FINGERPRINT, $result->fingerprint);
        self::assertFalse($result->isConfirmed());

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.neosidekick.test/api/agentic-chat/signing-key', (string)$request->getUri());
        self::assertSame('Bearer test-api-key', $request->getHeaderLine('Authorization'));

        $body = $this->lastRequestBody();
        self::assertSame(trim($this->fixturePublicKeyPem()), $body['public_key_pem']);
        self::assertSame(self::FIXTURE_KID, $body['kid']);
        self::assertSame('https://www.example.com', $body['domain']);
        self::assertSame('1.2.3', $body['plugin_version']);
        self::assertNull($body['chain_kid']);
        self::assertNull($body['chain_signature']);
        self::assertFalse($body['relabel']);
    }

    /** @test */
    public function anUnknownPluginVersionIsSentAsNullInsteadOfAnEmptyString(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()], pluginVersion: '');

        $service->push();

        self::assertNull($this->lastRequestBody()['plugin_version']);
    }

    /** @test */
    public function aChainedRotationIsSignedWithThePreviousPrivateKeyOverTheTrimmedPublicKeyPem(): void
    {
        $newPublicKeyPem = $this->rotatedPublicKeyPem();

        $chainSignature = AgentSigningKeyPushService::signRotationChain(
            $newPublicKeyPem,
            $this->fixturePrivateKeyPem()
        );

        self::assertNotNull($chainSignature);
        $decodedSignature = base64_decode($chainSignature, true);
        self::assertNotFalse($decodedSignature);
        // A signature over the untrimmed string would not verify on the backend.
        self::assertSame(
            1,
            openssl_verify(trim($newPublicKeyPem), $decodedSignature, $this->fixturePublicKeyPem(), OPENSSL_ALGO_SHA256)
        );
        self::assertSame(
            0,
            openssl_verify($newPublicKeyPem . "\n\n", $decodedSignature, $this->fixturePublicKeyPem(), OPENSSL_ALGO_SHA256)
        );
    }

    /** @test */
    public function signingARotationWithAnUnreadablePreviousKeyReturnsNull(): void
    {
        self::assertNull(AgentSigningKeyPushService::signRotationChain($this->rotatedPublicKeyPem(), 'not a private key'));
    }

    /** @test */
    public function chainFieldsAreForwardedToTheBackendAndAConfirmedStatusIsReported(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);

        $result = $service->push('previous-kid', 'previous-signature');

        self::assertTrue($result->successful);
        self::assertTrue($result->isConfirmed());
        $body = $this->lastRequestBody();
        self::assertSame('previous-kid', $body['chain_kid']);
        self::assertSame('previous-signature', $body['chain_signature']);
    }

    /** @test */
    public function pushIfNecessarySkipsWhenTheSameKeyIsFreshlyConfirmedAtTheSameTarget(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);

        self::assertNotNull($service->pushIfNecessary());
        self::assertNull($service->pushIfNecessary());
        self::assertCount(1, $this->requestHistory);
    }

    /**
     * Re-pushing is the only way the plugin learns the confirmation ceremony completed and can
     * leave the legacy mint mode.
     *
     * @test
     */
    public function pushIfNecessaryRePushesWhileTheRecordedStatusIsNotConfirmed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->pendingResponse(),
            $this->pendingResponse(),
            $this->confirmedResponse(),
        ]);

        self::assertNotNull($service->pushIfNecessary());
        self::assertNotNull($service->pushIfNecessary());
        self::assertNotNull($service->pushIfNecessary());
        self::assertNull($service->pushIfNecessary(), 'once confirmed, the push must stop');
        self::assertCount(3, $this->requestHistory);
    }

    /** @test */
    public function isKeyConfirmedReflectsTheRecordedStatusForTheCurrentKidAndTarget(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse(), $this->confirmedResponse()]);

        self::assertFalse($service->isKeyConfirmed(), 'no push recorded yet');

        $service->push();
        self::assertFalse($service->isKeyConfirmed(), 'a pending push must not count as confirmed');

        $service->push();
        self::assertTrue($service->isKeyConfirmed());
    }

    /** @test */
    public function isKeyConfirmedIsFalseWhenTheTargetChangedAfterTheConfirmation(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);
        $service->push();
        self::assertTrue($service->isKeyConfirmed());

        $this->setProtectedProperty($service, 'externalApiDomain', 'https://other.neosidekick.test');

        self::assertFalse($service->isKeyConfirmed());
    }

    /** @test */
    public function isKeyConfirmedIsFalseWithoutAKeypairOrTarget(): void
    {
        $serviceWithoutKeys = $this->createService([]);
        self::assertFalse($serviceWithoutKeys->isKeyConfirmed());

        $serviceWithoutTarget = $this->createServiceWithFixtureKeyPair([], externalApiDomain: '');
        self::assertFalse($serviceWithoutTarget->isKeyConfirmed());
    }

    /** @test */
    public function pushIfNecessaryPushesAgainWhenTheTargetChanged(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse(), $this->pendingResponse()]);
        $service->pushIfNecessary();

        $this->setProtectedProperty($service, 'externalApiDomain', 'https://other.neosidekick.test');

        self::assertNotNull($service->pushIfNecessary());
        self::assertCount(2, $this->requestHistory);
    }

    /** @test */
    public function pushIfNecessaryPushesAgainAfterTheKeypairWasRotated(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse(), $this->pendingResponse()]);
        $service->pushIfNecessary();

        $this->repository->clear();
        $this->repository->seed(new AgentSigningKeyRecord($this->rotatedPrivateKeyPem(), $this->rotatedPublicKeyPem()));
        $this->setProtectedProperty($service, 'agentKeyPairService', $this->createKeyPairService());

        self::assertNotNull($service->pushIfNecessary());
        self::assertCount(2, $this->requestHistory);
        self::assertNotSame(self::FIXTURE_KID, $this->lastRequestBody()['kid']);
    }

    /**
     * The authorization is the one authenticated path that mints the first keypair - and it
     * pushes it in the same breath, so the key never exists without the backend learning of it.
     *
     * @test
     */
    public function pushIfNecessaryOnAKeylessInstallationGeneratesTheKeypairAndPushesIt(): void
    {
        $service = $this->createService([$this->confirmedResponse('root-kid-1', 'fresh-kid')]);

        $result = $service->pushIfNecessary();

        self::assertNotNull($result);
        self::assertTrue($result->successful, (string)$result->errorMessage);
        self::assertCount(1, $this->requestHistory);
        $keyPairService = $this->createKeyPairService();
        self::assertTrue($keyPairService->hasKeyPair(), 'the row exists afterwards');
        self::assertSame($keyPairService->getKeyId(), $this->lastRequestBody()['kid']);
        self::assertNull($this->lastRequestBody()['chain_kid'], 'a first key enrolls unchained');
    }

    /**
     * An installation without an API domain has nowhere to push to, so it must not mint an
     * identity nobody will ever trust - the target check comes before the generation.
     *
     * @test
     */
    public function pushIfNecessaryWithoutATargetCreatesNoKeypair(): void
    {
        $service = $this->createService([$this->pendingResponse()], externalApiDomain: '');

        self::assertNull($service->pushIfNecessary());
        self::assertCount(0, $this->requestHistory);
        self::assertNull($this->repository->findInstallRecord(), 'no row was created');
    }

    /** @test */
    public function anUnreachableBackendIsLoggedAsAWarningAndNeverThrows(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $service = $this->createServiceWithFixtureKeyPair(
            [new ConnectException('Connection refused', new Request('POST', 'https://api.neosidekick.test'))],
            logger: $logger
        );

        $result = $service->push();

        self::assertFalse($result->successful);
        self::assertNotNull($result->errorMessage);
        self::assertFalse($this->hasRecordedPush());
    }

    /** @test */
    public function aRejectedKeyIsReportedAsAFailureAndNotRecordedAsPushed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Signing key rejected.","reason":"kid_mismatch"}'),
            $this->pendingResponse(),
        ]);

        $result = $service->push();

        self::assertFalse($result->successful);
        self::assertStringContainsString('kid_mismatch', (string)$result->errorMessage);
        self::assertFalse($this->hasRecordedPush());
        // Not recorded means the next authorization retries it.
        self::assertNotNull($service->pushIfNecessary());
        self::assertCount(2, $this->requestHistory);
    }

    /** @test */
    public function anUnreadableResponseBodyIsAFailureAndNotRecordedAsPushed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([new Response(200, [], 'this is not json')]);

        $result = $service->push();

        self::assertFalse($result->successful);
        self::assertFalse($this->hasRecordedPush());
    }

    /** @test */
    public function pushWithoutAConfiguredBackendFailsWithoutARequest(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()], externalApiDomain: '');

        $result = $service->push();

        self::assertFalse($result->successful);
        self::assertCount(0, $this->requestHistory);
        self::assertNull($service->pushIfNecessary());
    }

    /**
     * The pushed domain is the backend's renewal allowlist entry, so registering a guessed
     * "http://localhost" would silently disable renewal forever.
     *
     * @test
     */
    public function pushIsRefusedWhenTheSiteDomainCanOnlyBeGuessed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()], trustedDomain: null);

        $result = $service->push();

        self::assertFalse($result->successful);
        self::assertStringContainsString('Neos.Flow.http.baseUri', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory);
        self::assertFalse($this->hasRecordedPush());
    }

    /** @test */
    public function pushIfNecessaryAlsoRefusesToRegisterAGuessedDomain(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()], trustedDomain: null);

        $result = $service->pushIfNecessary();

        self::assertNotNull($result);
        self::assertFalse($result->successful);
        self::assertCount(0, $this->requestHistory);
    }

    /** @test */
    public function anExplicitDomainOverrideIsUsedInsteadOfTheResolvedOneAndIsNormalised(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()], trustedDomain: null);

        $result = $service->push(null, null, 'https://www.example.com/');

        self::assertTrue($result->successful);
        self::assertSame('https://www.example.com', $this->lastRequestBody()['domain']);
    }

    /** @test */
    public function anExplicitDomainOverrideWinsOverTheResolvedSiteDomain(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()]);

        $service->push(null, null, 'https://staging.example.com');

        self::assertSame('https://staging.example.com', $this->lastRequestBody()['domain']);
    }

    /**
     * A dashboard-side revocation is only ever learned by pushing again.
     *
     * @test
     */
    public function pushIfNecessaryPushesAgainOnceTheConfirmationRecordAgedPastADay(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse(),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'status' => 'revoked',
                'kid' => self::FIXTURE_KID,
                'fingerprint' => self::FIXTURE_FINGERPRINT,
            ], JSON_THROW_ON_ERROR)),
        ]);

        self::assertNotNull($service->pushIfNecessary());
        self::assertNull($service->pushIfNecessary(), 'a fresh confirmation stops the push');

        $this->agePushStateBySeconds(90000);

        $result = $service->pushIfNecessary();
        self::assertNotNull($result, 'a stale confirmation must be re-pushed');
        self::assertCount(2, $this->requestHistory);
        self::assertSame('revoked', $result->status);
        self::assertFalse($service->isKeyConfirmed(), 'the revocation reported by the backend must be learned');
        // A recorded revocation backs off like a confirmation: one probe per day, not one per authorization.
        self::assertNull($service->pushIfNecessary(), 'a fresh revocation record must not be re-pushed on every call');
        self::assertCount(2, $this->requestHistory);
        $this->agePushStateBySeconds(90000);
        $service->pushClient = new Client(['handler' => HandlerStack::create(new MockHandler([$this->confirmedResponse()]))]);
        self::assertNotNull($service->pushIfNecessary(), 'a stale revocation record is probed again');
    }

    /**
     * The push is the only writer of the version the backend knows about, so an upgrade must not
     * wait out the 24 h confirmation cache before reporting itself.
     *
     * @test
     */
    public function pushIfNecessaryPushesAgainWhenThePluginVersionChanged(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse(), $this->confirmedResponse()]);

        self::assertNotNull($service->pushIfNecessary());
        self::assertNull($service->pushIfNecessary(), 'the same version within the day must not be re-pushed');

        $this->setProtectedProperty($service, 'neosidekickInternalHelper', $this->createInternalHelper('3.1.0'));

        self::assertNotNull($service->pushIfNecessary(), 'an upgraded installation must re-push immediately');
        self::assertCount(2, $this->requestHistory);
        self::assertSame('3.1.0', $this->lastRequestBody()['plugin_version']);
        self::assertSame('3.1.0', $this->recordedPushState()['plugin_version']);
        self::assertNull($service->pushIfNecessary(), 'the new version is recorded, so the cache applies again');
    }

    /**
     * A push recorded before the version was stored carries none, which reads as an unknown
     * version and therefore as stale - one redundant push, then the record is complete.
     *
     * @test
     */
    public function aPushStateWithoutARecordedVersionIsStale(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse(), $this->confirmedResponse()]);

        self::assertNotNull($service->pushIfNecessary());
        $this->removeRecordedPluginVersion();

        self::assertNotNull($service->pushIfNecessary(), 'a legacy push record must be re-pushed');
        self::assertCount(2, $this->requestHistory);
        self::assertNull($service->pushIfNecessary(), 'the re-push records the version, so it is stale only once');
    }

    /** @test */
    public function getPushStatusReportsTheRecordedStatusAndTimestampForTheCurrentKidAndTarget(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->pendingResponse()]);

        self::assertSame(['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null], $service->getPushStatus());

        $service->push();

        $status = $service->getPushStatus();
        self::assertSame('pending', $status['status']);
        self::assertNotSame('', $status['pushedAt']);

        $this->setProtectedProperty($service, 'externalApiDomain', 'https://other.neosidekick.test');
        self::assertSame('none', $service->getPushStatus()['status'], 'a state recorded for another target must not be shown');
    }

    /** @test */
    public function getPushStatusReportsNoneWithoutAKeypair(): void
    {
        self::assertSame(['status' => 'none', 'pushedAt' => '', 'registeredDomain' => null], $this->createService([])->getPushStatus());
    }

    /** @test */
    public function theEchoedInstallRootKidIsRecordedAndCarriedInTheResult(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-1')]);

        $result = $service->push();

        self::assertSame('root-kid-1', $result->installRootKid);
        self::assertSame('root-kid-1', $this->recordedPushState()['install_root_kid']);
    }

    /** @test */
    public function aResponseWithoutAnInstallRootKidRecordsNone(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);

        $result = $service->push();

        self::assertNull($result->installRootKid);
        self::assertArrayNotHasKey('install_root_kid', $this->recordedPushState());
    }

    /**
     * The whole protocol on the happy path: the successor waits as a pending pair, is transmitted
     * chained to the live key with the long timeout, and becomes the live pair only on the 200.
     *
     * @test
     */
    public function rotateKeyPairWritesAPendingPairPushesItChainedAndPromotesItOnConfirmation(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $result = $service->rotateKeyPair();

        self::assertTrue($result->successful, (string)$result->errorMessage);
        $keyPairService = $this->createKeyPairService();
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair is promoted on confirmation');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is the successor now');
        $record = $this->requireRecord();
        self::assertNull($record->getPendingPrivateKeyPem());
        self::assertNull($record->getPendingPublicKeyPem());

        $body = $this->lastRequestBody();
        self::assertSame(trim($keyPairService->getPublicKeyPem()), $body['public_key_pem'], 'the PENDING (now live) key was transmitted');
        self::assertSame($keyPairService->getKeyId(), $body['kid']);
        self::assertSame(self::FIXTURE_KID, $body['chain_kid'], 'chained to the live key');
        $signature = base64_decode((string)$body['chain_signature'], true);
        self::assertNotFalse($signature);
        self::assertSame(1, openssl_verify($body['public_key_pem'], $signature, $this->fixturePublicKeyPem(), OPENSSL_ALGO_SHA256));

        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([15], $service->requestedTimeouts, 'a regeneration is not on the authorization budget');
        $state = $this->recordedPushState();
        self::assertSame($keyPairService->getKeyId(), $state['kid']);
        self::assertSame('confirmed', $state['status']);
        self::assertSame('root-kid-1', $state['install_root_kid']);
        self::assertTrue($service->isKeyConfirmed());
        self::assertSame('confirmed', $service->getPushStatus()['status']);
    }

    /**
     * The rotation stamps its pair as transmitted before the push goes out, so the automatic
     * editor-authorize retry backs off for a minute instead of picking up a freshly minted
     * successor - one that may carry a relabel intent - while the rotation is still in flight.
     *
     * @test
     */
    public function aRotationStampsItsPendingPairSoTheAutomaticRetryBacksOffWhileTheRotationIsInFlight(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([new Response(503, [], 'maintenance')]);

        self::assertFalse($service->rotateKeyPair()->successful);

        self::assertNotNull(
            $this->createKeyPairService()->getPendingKeyPairRetriedAt(),
            'the pending pair is stamped before the rotation push goes out'
        );
        self::assertNull($service->pushIfNecessary(), 'the automatic retry backs off instead of transmitting the fresh pair');
        self::assertCount(1, $this->requestHistory, 'only the rotation itself was transmitted');
    }

    /** @test */
    public function aRejectedRotationKeepsTheLivePairAndThePendingPairAndReportsTheReasonVerbatim(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Signing key rejected.","reason":"chain_domain_mismatch"}'),
        ]);

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertSame('chain_domain_mismatch', $result->rejectionReason);
        self::assertStringContainsString('Signing key rejected. (chain_domain_mismatch)', (string)$result->errorMessage);
        $keyPairService = $this->createKeyPairService();
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is untouched');
        self::assertSame($this->fixturePublicKeyPem(), $keyPairService->getPublicKeyPem());
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'the pending pair waits for the next attempt');
        self::assertFalse($this->hasRecordedPush());
    }

    /** @test */
    public function anUnreadable200BodyKeepsThePendingPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([new Response(200, [], 'this is not json')]);

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertNull($result->rejectionReason);
        self::assertTrue($this->createKeyPairService()->hasPendingKeyPair());
        self::assertSame(self::FIXTURE_KID, $this->createKeyPairService()->getKeyId());
    }

    /** @test */
    public function anUnreachableBackendDuringARotationKeepsThePendingPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new ConnectException('Connection timed out', new Request('POST', 'https://api.neosidekick.test')),
        ]);

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertNull($result->rejectionReason);
        self::assertTrue($this->createKeyPairService()->hasPendingKeyPair());
    }

    /**
     * Every push path transmits the SAME pending key while one exists - never the live key and
     * never a third one - so a lost confirmation converges on the next push.
     *
     * @test
     */
    public function everyPushPathTransmitsTheSamePendingKeyChainedAfreshUntilItIsConfirmed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(503, [], 'maintenance'),
            new Response(503, [], 'maintenance'),
            new Response(503, [], 'maintenance'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);

        self::assertFalse($service->rotateKeyPair()->successful);
        $pendingPublicKeyPem = (string)$this->requireRecord()->getPendingPublicKeyPem();
        $firstSignature = $this->lastRequestBody()['chain_signature'];

        self::assertFalse($service->push('ignored-kid', 'ignored-signature')->successful, 'agentkey:push transmits the pending key');
        self::assertSame(trim($pendingPublicKeyPem), $this->lastRequestBody()['public_key_pem']);
        self::assertSame(self::FIXTURE_KID, $this->lastRequestBody()['chain_kid']);
        self::assertNotSame('ignored-signature', $this->lastRequestBody()['chain_signature']);

        $this->agePendingPairBySeconds(120);
        $result = $service->pushIfNecessary();
        self::assertNotNull($result, 'the authorization flow re-pushes an unconfirmed regeneration');
        self::assertFalse($result->successful);
        self::assertSame(trim($pendingPublicKeyPem), $this->lastRequestBody()['public_key_pem']);
        self::assertSame($firstSignature, $this->lastRequestBody()['chain_signature'], 'RSA PKCS#1 v1.5 signatures are deterministic: same key, same chain');

        self::assertTrue($service->rotateKeyPair()->successful, 'a second regeneration re-transmits the pending pair instead of creating a third key');
        self::assertSame(trim($pendingPublicKeyPem), $this->lastRequestBody()['public_key_pem']);
        self::assertSame($pendingPublicKeyPem, $this->requireRecord()->getPublicKeyPem(), 'the same key was promoted');
        self::assertFalse($this->createKeyPairService()->hasPendingKeyPair());
        self::assertCount(4, $this->requestHistory);
        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([15, 15, 2, 15], $service->requestedTimeouts, 'the explicit push of a pending key is a regeneration retry, the authorization flow stays on its budget');
    }

    /**
     * A failed regeneration must not turn every authorization into a push: the pending key is
     * retried once a minute, stamped on the row before each attempt.
     *
     * @test
     */
    public function pushIfNecessaryRetriesAPendingKeyOnlyOnceItsLastAttemptIsOlderThanAMinute(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(503, [], 'maintenance'),
            new Response(503, [], 'maintenance'),
        ]);
        self::assertFalse($service->rotateKeyPair()->successful);

        self::assertNull($service->pushIfNecessary(), 'a pending pair just transmitted is not retried');
        self::assertCount(1, $this->requestHistory);

        $this->agePendingPairBySeconds(120);
        self::assertNotNull($service->pushIfNecessary(), 'an attempt older than a minute is repeated');
        self::assertCount(2, $this->requestHistory);
        self::assertGreaterThanOrEqual(
            time() - 5,
            (int)$this->requireRecord()->getPendingRetriedAt()?->getTimestamp(),
            'the attempt is stamped'
        );
        self::assertNull($service->pushIfNecessary(), 'and backs off again');
        self::assertCount(2, $this->requestHistory);
    }

    /**
     * The lost-confirmation case: the rotation's 200 never arrived, so the pending pair waits
     * while the backend already stored the successor. The next authorization-path retry hits the
     * backend's already-known-kid short-circuit, gets the confirmed answer within the 2 s
     * budget and promotes the pair - no third key, no manual step.
     *
     * @test
     */
    public function pushIfNecessaryPromotesAPendingPairOnTheConfirmedAnswerOfARetryWithinTheAuthorizationBudget(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(503, [], 'maintenance'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        self::assertFalse($service->rotateKeyPair()->successful, 'the rotation answer was lost');
        $pendingPublicKeyPem = (string)$this->requireRecord()->getPendingPublicKeyPem();
        $this->agePendingPairBySeconds(120);

        $result = $service->pushIfNecessary();

        self::assertNotNull($result);
        self::assertTrue($result->successful, (string)$result->errorMessage);
        $keyPairService = $this->createKeyPairService();
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair is committed');
        self::assertSame($pendingPublicKeyPem, $keyPairService->getPublicKeyPem(), 'the pending key is the live key now');
        self::assertNull($this->requireRecord()->getPendingPrivateKeyPem());
        self::assertSame(trim($pendingPublicKeyPem), $this->lastRequestBody()['public_key_pem'], 'the same pending key was retransmitted');
        $state = $this->recordedPushState();
        self::assertSame($keyPairService->getKeyId(), $state['kid']);
        self::assertSame('confirmed', $state['status']);
        self::assertTrue($service->isKeyConfirmed());
        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([15, 2], $service->requestedTimeouts, 'the retry runs on the authorization budget');
    }

    /**
     * The HTTP exchange of a rotation runs outside any lock. Rotation A transmits P; meanwhile an
     * authorization pushes P, gets its 200 and commits P; the administrator presses again and C
     * prepares Q. A's late 200 for P must not promote Q - a key the backend never confirmed - and
     * must not touch the live pair. The commit names the key the backend confirmed, so it is a
     * no-op here: P already IS the live pair, and A reports its own (true) answer for P without
     * re-recording the push B recorded.
     *
     * @test
     */
    public function aLateConfirmationOfAnAlreadyCommittedKeyNeverPromotesTheNextPendingKey(): void
    {
        $serviceB = $this->createServiceWithFixtureKeyPair([]);
        $lateAnswer = function () use ($serviceB): Response {
            $this->agePendingPairBySeconds(120);
            $serviceB->pushClient = new Client(['handler' => HandlerStack::create(new MockHandler([$this->confirmedResponse('root-kid-1', 'kid-p')]))]);
            self::assertTrue($serviceB->pushIfNecessary()->successful, 'B transmits P and commits it');
            self::assertFalse($this->createKeyPairService()->hasPendingKeyPair());
            $this->createKeyPairService()->preparePendingKeyPair();

            return $this->confirmedResponse('root-kid-1', 'kid-p');
        };
        $serviceA = $this->createService([$lateAnswer]);

        $resultA = $serviceA->rotateKeyPair();

        $keyIdOfP = $this->lastRequestBody()['kid'];
        self::assertTrue($resultA->successful, 'A reports its own answer for P, which the backend did confirm');
        $keyPairService = $this->createKeyPairService();
        self::assertSame($keyIdOfP, $keyPairService->getKeyId(), 'P is the live pair, Q was not promoted');
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'Q still waits');
        self::assertNotSame($keyIdOfP, AgentKeyPairService::deriveKeyId($keyPairService->getPendingPublicKeyPem()));
        self::assertSame($keyIdOfP, $this->recordedPushState()['kid']);
    }

    /**
     * The same race without the second press: the late caller succeeds as a no-op and reports
     * its own answer, but does not overwrite the state the concurrent push recorded.
     *
     * @test
     */
    public function aLateConfirmationOfAnAlreadyCommittedKeyIsANoOpThatDoesNotReRecordThePush(): void
    {
        $serviceB = $this->createServiceWithFixtureKeyPair([]);
        $lateAnswer = function () use ($serviceB): Response {
            $this->agePendingPairBySeconds(120);
            $serviceB->pushClient = new Client(['handler' => HandlerStack::create(new MockHandler([$this->confirmedResponse('root-kid-b', 'kid-p')]))]);
            self::assertTrue($serviceB->pushIfNecessary()->successful);

            return $this->confirmedResponse('root-kid-a', 'kid-p');
        };
        $serviceA = $this->createService([$lateAnswer]);

        $resultA = $serviceA->rotateKeyPair();

        self::assertTrue($resultA->successful);
        self::assertSame('root-kid-a', $resultA->installRootKid, 'the late caller reports its own answer');
        self::assertSame('root-kid-b', $this->recordedPushState()['install_root_kid'], 'the concurrent push\'s record stands');
        self::assertFalse($this->createKeyPairService()->hasPendingKeyPair());
    }

    /**
     * A commit that changes no row is never a success: reporting "confirmed" while the live key
     * is the one the backend just revoked would strand the installation silently.
     *
     * @test
     */
    public function aCommitThatChangesNoRowIsReportedAsAFailureAndRecordsNoPush(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            function (): Response {
                $this->setRecordColumn('pendingKid', 'the-kid-of-a-later-regeneration');

                return $this->confirmedResponse('root-kid-1', 'new-kid');
            },
        ]);

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful, 'a 0-row commit must never be reported as success');
        self::assertStringContainsString('a later regeneration replaced it', (string)$result->errorMessage);
        self::assertFalse($this->hasRecordedPush());
        self::assertSame(self::FIXTURE_KID, $this->createKeyPairService()->getKeyId(), 'the live pair is untouched');
    }

    /**
     * The row write is the one place the private key could leak into a message an administrator
     * or a log file gets to see (DBAL appends bound parameters to its exception text).
     *
     * @test
     */
    public function aRowWriteFailureIsReportedWithoutAnyKeyMaterial(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);
        $this->repository->failNextWrite();

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertStringContainsString('signing-key row write failed', (string)$result->errorMessage);
        self::assertStringNotContainsString('-----BEGIN', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory);
    }

    /**
     * A stored pair whose halves do not belong together is refused by the keypair service; for
     * the gate that means "not confirmed", never an exception.
     *
     * @test
     */
    public function aSplitLivePairIsNotConfirmed(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse()]);
        $service->push();
        self::assertTrue($service->isKeyConfirmed());

        $this->setRecordColumn('publicKeyPem', $this->rotatedPublicKeyPem());
        $this->setProtectedProperty($service, 'agentKeyPairService', $this->createKeyPairService());

        self::assertFalse($service->isKeyConfirmed());
        self::assertSame('none', $service->getPushStatus()['status']);
    }

    /**
     * The relabel flag is a property of the pending key, not of the call: every re-push of that
     * key carries it until the commit removes it.
     *
     * @test
     */
    public function theRelabelFlagIsPersistedWithThePendingPairAndCarriedByEveryRePushUntilTheCommit(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(503, [], 'maintenance'),
            new Response(503, [], 'maintenance'),
            new Response(503, [], 'maintenance'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);

        self::assertFalse($service->rotateKeyPair(null, true)->successful);
        self::assertTrue($this->lastRequestBody()['relabel']);
        self::assertTrue($this->requireRecord()->isPendingRelabel());

        $this->agePendingPairBySeconds(120);
        self::assertNotNull($service->pushIfNecessary());
        self::assertTrue($this->lastRequestBody()['relabel'], 'the authorization flow re-sends the flag');

        self::assertFalse($service->push()->successful);
        self::assertTrue($this->lastRequestBody()['relabel'], 'agentkey:push re-sends the flag');

        self::assertTrue($service->rotateKeyPair()->successful, 'a plain --force re-run');
        self::assertTrue($this->lastRequestBody()['relabel'], 'still relabelling: the flag belongs to the pending key');
        self::assertFalse($this->requireRecord()->isPendingRelabel(), 'the commit resets it');

        self::assertTrue($service->rotateKeyPair()->successful, 'the next regeneration starts without it');
        self::assertFalse($this->lastRequestBody()['relabel']);
    }

    /**
     * The relabel intent arrives on a pending pair that already exists without it (the first
     * press did not ask, the second one does). The flag is written past the identity map, so
     * the payload only carries it if the managed row was refreshed after that write - the
     * refresh is what this pins, by requiring it on the EntityManager the keypair service holds.
     *
     * @test
     */
    public function relabellingAnExistingPendingPairIsWrittenRefreshedAndTransmitted(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);
        $entityManager->expects(self::atLeastOnce())->method('refresh')->with(self::isInstanceOf(AgentSigningKeyRecord::class));
        $service = $this->createServiceWithFixtureKeyPair([
            new Response(503, [], 'maintenance'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        $this->setProtectedProperty($service, 'agentKeyPairService', $this->createKeyPairService($entityManager));

        self::assertFalse($service->rotateKeyPair()->successful);
        self::assertFalse($this->lastRequestBody()['relabel']);
        self::assertFalse($this->requireRecord()->isPendingRelabel());

        self::assertTrue($service->rotateKeyPair(null, true)->successful);

        self::assertTrue($this->lastRequestBody()['relabel'], 'the re-push of the SAME pending key carries the relabel intent');
        self::assertSame(trim($this->requireRecord()->getPublicKeyPem()), $this->lastRequestBody()['public_key_pem'], 'the promoted key is the one that was transmitted - no third key was created');
    }

    /** @test */
    public function aChangedInstallRootKidAfterARotationIsReportedAsReenrolled(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();

        $result = $service->rotateKeyPair();

        self::assertTrue($result->successful);
        self::assertSame('root-kid-2', $result->installRootKid);
        self::assertSame('reenrolled', $service->getPushStatus()['status']);
        self::assertTrue($service->isKeyConfirmed(), 'a re-enrolled key is still a confirmed key');
        self::assertTrue($this->recordedPushState()['reenrolled']);
        self::assertSame($this->recordedPushState()['pushed_at'], $this->recordedPushState()['reenrolled_at']);
    }

    /**
     * The announcement outlives the routine pushes that follow it - they confirm the same
     * lineage. A routine push the same target answers with yet another root is itself a
     * re-enrolment (the backend enrolled the key anew) and is announced afresh, dated by that push.
     *
     * @test
     */
    public function theReenrolmentIsCarriedForwardByRoutinePushesOfTheSameLineage(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
            $this->confirmedResponse('root-kid-3', 'new-kid'),
        ]);
        $service->push();
        $service->rotateKeyPair();
        $reenrolledAt = $this->recordedPushState()['reenrolled_at'];
        $this->agePushStateBySeconds(90000);

        self::assertNotNull($service->pushIfNecessary(), 'the daily probe');

        self::assertSame('reenrolled', $service->getPushStatus()['status']);
        self::assertSame($reenrolledAt, $this->recordedPushState()['reenrolled_at'], 'the original date, not the probe\'s');

        $this->agePushStateBySeconds(90000);
        self::assertNotNull($service->pushIfNecessary());
        self::assertSame('reenrolled', $service->getPushStatus()['status'], 'another lineage at the same target: the probe itself enrolled the key anew');
        self::assertSame('root-kid-3', $this->recordedPushState()['install_root_kid']);
        self::assertSame($this->recordedPushState()['pushed_at'], $this->recordedPushState()['reenrolled_at'], 'dated by the push that changed the root');
    }

    /** @test */
    public function theReenrolmentIsNotCarriedToAnotherTarget(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();
        $service->rotateKeyPair();
        self::assertSame('reenrolled', $service->getPushStatus()['status']);

        $this->setProtectedProperty($service, 'externalApiDomain', 'https://other.neosidekick.test');
        self::assertNotNull($service->pushIfNecessary());

        self::assertSame('confirmed', $service->getPushStatus()['status']);
    }

    /** @test */
    public function anUnchangedInstallRootKidAfterARotationStaysRegistered(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        $service->push();

        $service->rotateKeyPair();

        self::assertSame('confirmed', $service->getPushStatus()['status']);
        self::assertArrayNotHasKey('reenrolled', $this->recordedPushState());
    }

    /**
     * A push recorded before the field existed must read as "unknown", never as "changed" -
     * otherwise the first rotation after the upgrade would announce a re-enrollment everywhere.
     *
     * @test
     */
    public function aStateWithoutAnInstallRootKidIsUnknownAndNeverReadsAsChanged(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse(),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();
        self::assertArrayNotHasKey('install_root_kid', $this->recordedPushState());

        $service->rotateKeyPair();

        self::assertSame('confirmed', $service->getPushStatus()['status']);
        self::assertSame('root-kid-2', $this->recordedPushState()['install_root_kid']);
    }

    /**
     * With the live private key unusable nothing can vouch for a successor, and an unchained push
     * would enrol a new installation behind the operator's back - orphaning every connected tool.
     * The rotation stops before it mints anything, and the row stays restorable from a backup.
     *
     * @test
     */
    public function anUnusableLivePrivateKeyAbortsTheRotationWithoutPreparingASuccessor(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->setRecordColumn('privateKeyPem', 'this is not a private key');

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertStringContainsString('the stored keypair is unusable', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
        self::assertFalse($this->createKeyPairService()->hasPendingKeyPair(), 'no successor was minted');
        self::assertSame('this is not a private key', $this->requireRecord()->getPrivateKeyPem(), 'the live row is untouched');
    }

    /**
     * A successor left over from an earlier attempt is neither pushed nor replaced: the abort is
     * about the live key, and the pending pair stays exactly as it was.
     *
     * @test
     */
    public function anUnusableLivePrivateKeyWithAPreExistingPendingPairAbortsAndKeepsThatPair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->createKeyPairService()->preparePendingKeyPair();
        $pendingKid = $this->requireRecord()->getPendingKid();
        $livePublicKeyPem = $this->requireRecord()->getPublicKeyPem();
        $this->setRecordColumn('privateKeyPem', 'this is not a private key');

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertStringContainsString('the stored keypair is unusable', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
        self::assertSame($pendingKid, $this->requireRecord()->getPendingKid(), 'the waiting successor is untouched');
        self::assertSame('this is not a private key', $this->requireRecord()->getPrivateKeyPem());
        self::assertSame($livePublicKeyPem, $this->requireRecord()->getPublicKeyPem());
    }

    /**
     * The editor-request retry of a waiting successor never re-enrols: on an unusable live key it
     * fails, leaving both the live columns and the successor as they are.
     *
     * @test
     */
    public function pushIfNecessaryWithAPendingPairOnAnUnusableLivePairFailsWithoutReenrolling(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->createKeyPairService()->preparePendingKeyPair();
        $pendingKid = $this->requireRecord()->getPendingKid();
        $livePublicKeyPem = $this->requireRecord()->getPublicKeyPem();
        $this->setRecordColumn('privateKeyPem', 'this is not a private key');

        $result = $service->pushIfNecessary();

        self::assertNotNull($result);
        self::assertFalse($result->successful);
        self::assertStringContainsString('the stored keypair is unusable', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
        self::assertSame($pendingKid, $this->requireRecord()->getPendingKid(), 'the waiting successor is untouched');
        self::assertSame('this is not a private key', $this->requireRecord()->getPrivateKeyPem());
        self::assertSame($livePublicKeyPem, $this->requireRecord()->getPublicKeyPem());
        self::assertNull($this->requireRecord()->getPushReenrolled(), 'no re-enrolment was recorded');
    }

    /**
     * The irreducible residual, now behind an administrator's explicit re-enrolment: the pending
     * key is announced unchained and the backend enrols a new installation.
     *
     * @test
     */
    public function anUnusableLivePrivateKeyRotatesUnchainedOnlyWithExplicitReenrolment(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->setRecordColumn('privateKeyPem', 'this is not a private key');

        $result = $service->rotateKeyPair(null, false, true);

        self::assertTrue($result->successful, (string)$result->errorMessage);
        self::assertNull($this->lastRequestBody()['chain_kid']);
        self::assertNull($this->lastRequestBody()['chain_signature']);
        self::assertFalse($this->createKeyPairService()->hasPendingKeyPair(), 'the pending pair replaced the unusable live pair');
        self::assertStringContainsString('PRIVATE KEY', $this->requireRecord()->getPrivateKeyPem());
    }

    /**
     * A storage read that fails while chaining is a transient database problem, not an unusable
     * key: it must surface as itself, or a re-enrolment flag would let it burn the lineage.
     *
     * @test
     */
    public function aStorageFailureWhileChainingSurfacesInsteadOfCountingAsAnUnusableKey(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->setProtectedProperty($service, 'agentKeyPairService', $this->createKeyPairServiceFailingToReadThePrivateKey());

        $result = $service->rotateKeyPair(null, false, true);

        self::assertFalse($result->successful);
        self::assertStringContainsString('could not be loaded from the database', (string)$result->errorMessage);
        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
    }

    /**
     * The administrator's exit from a keyless installation (a deleted row, a restore predating
     * the key): the regeneration generates the live pair and enrolls it unchained.
     *
     * @test
     */
    public function rotateKeyPairWithoutALivePairEnrollsAFreshPairUnchained(): void
    {
        $service = $this->createService([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $result = $service->rotateKeyPair();

        self::assertTrue($result->successful);
        self::assertTrue($this->createKeyPairService()->hasKeyPair());
        self::assertFalse($this->createKeyPairService()->hasPendingKeyPair());
        self::assertNull($this->lastRequestBody()['chain_kid']);
    }

    /**
     * The copy case: the live pair is perfectly usable, so the chain CAN be computed - and is,
     * because a storage failure while computing it must still abort. An explicit re-enrolment
     * then discards it, because a chained announcement from a copy is refused by the backend's
     * clone rule. The backend answers with another lineage, which reads as re-enrolled.
     *
     * @test
     */
    public function anExplicitReenrolmentAnnouncesAUsableLivePairsSuccessorUnchained(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();
        self::assertTrue($this->createKeyPairService()->isLiveKeyPairUsable(), 'the copy carries a readable production key');

        $result = $service->rotateKeyPair(null, false, true);

        self::assertTrue($result->successful, (string)$result->errorMessage);
        $body = $this->lastRequestBody();
        self::assertNull($body['chain_kid'], 'the computed chain is discarded, not kept');
        self::assertNull($body['chain_signature']);
        self::assertFalse($body['relabel']);
        self::assertNotSame(self::FIXTURE_KID, $body['kid'], 'the successor, not the copied live key, is announced');
        self::assertSame('reenrolled', $service->getPushStatus()['status']);
        self::assertTrue($this->recordedPushState()['reenrolled']);
    }

    /**
     * Without the flag the very same usable pair still vouches for its successor - the ordinary
     * rotation, which the backend accepts and which keeps the installation's identity. This is
     * the negative control for the re-enrolment arm; the chaining guard proper is
     * {@see rotateKeyPairWritesAPendingPairPushesItChainedAndPromotesItOnConfirmation}.
     *
     * @test
     */
    public function aRotationWithoutReenrolmentStillChainsToAUsableLivePair(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $result = $service->rotateKeyPair(null, false, false);

        self::assertTrue($result->successful, (string)$result->errorMessage);
        $body = $this->lastRequestBody();
        self::assertSame(self::FIXTURE_KID, $body['chain_kid']);
        self::assertNotNull($body['chain_signature']);
    }

    /**
     * The confirmed hazard: a dump taken while the original had a rotation in flight carries the
     * original's PENDING private key. Announcing that pair unchained would root a new lineage at
     * a kid the original still holds, and the original's own later push would land it in the
     * copy's lineage. A re-enrolment therefore mints its own successor.
     *
     * @test
     */
    public function aReenrolmentReplacesAnInheritedSuccessorInsteadOfAnnouncingIt(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-2', 'new-kid')]);
        $this->createKeyPairService()->preparePendingKeyPair();
        $inheritedKid = $this->requireRecord()->getPendingKid();
        self::assertNotNull($inheritedKid);

        $result = $service->rotateKeyPair(null, false, true);

        self::assertTrue($result->successful, (string)$result->errorMessage);
        self::assertNotSame($inheritedKid, $this->lastRequestBody()['kid'], 'the inherited successor was never announced');
        self::assertNull($this->lastRequestBody()['chain_kid']);
    }

    /**
     * The same hazard on the relabel path, with the opposite ending: announcing the inherited
     * pair would make the backend short-circuit on a kid it already knows and let the copy commit
     * the original's live private key while the panel reads "confirmed". A relabel therefore
     * mints its own successor too - chained, because a relabel is a chained rotation.
     *
     * @test
     */
    public function aRelabelReplacesAnInheritedSuccessorAndStillChains(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([$this->confirmedResponse('root-kid-1', 'new-kid')]);
        $this->createKeyPairService()->preparePendingKeyPair();
        $inheritedKid = $this->requireRecord()->getPendingKid();
        self::assertNotNull($inheritedKid);

        $result = $service->rotateKeyPair(null, true, false);

        self::assertTrue($result->successful, (string)$result->errorMessage);
        $body = $this->lastRequestBody();
        self::assertNotSame($inheritedKid, $body['kid'], 'the inherited successor was never announced');
        self::assertTrue($body['relabel']);
        self::assertSame(self::FIXTURE_KID, $body['chain_kid'], 'a relabel is a chained rotation');
    }

    /**
     * Only the backend knows which domain a lineage is registered under, so its echo is recorded
     * verbatim on every successful push. An answer without the key - an older backend - must
     * OVERWRITE it: a label carried forward would keep claiming a conflict, or hide one.
     *
     * @test
     */
    public function theEchoedRegisteredDomainIsRecordedAndAMissingEchoOverwritesIt(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1', self::FIXTURE_KID, 'https://www.production.example'),
            $this->confirmedResponse('root-kid-1'),
        ]);

        $result = $service->push();

        self::assertSame('https://www.production.example', $result->registeredDomain);
        self::assertSame('https://www.production.example', $this->requireRecord()->getPushRegisteredDomain());
        self::assertSame('https://www.production.example', $service->getPushStatus()['registeredDomain']);

        $secondResult = $service->push();

        self::assertNull($secondResult->registeredDomain);
        self::assertNull($this->requireRecord()->getPushRegisteredDomain(), 'the old label is not carried forward');
        self::assertNull($service->getPushStatus()['registeredDomain']);
    }

    /**
     * The rotation path records it as well: a successful re-enrolment or relabel overwrites the
     * column with the lineage's current label, which is what makes the panel notice clear itself.
     *
     * @test
     */
    public function aRotationRecordsTheEchoedRegisteredDomainToo(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1', 'new-kid', 'https://www.staging.example'),
        ]);

        $service->rotateKeyPair(null, false, true);

        self::assertSame('https://www.staging.example', $this->requireRecord()->getPushRegisteredDomain());
        self::assertSame('https://www.staging.example', $service->getPushStatus()['registeredDomain']);
    }

    /** @test */
    public function getPushDomainReportsTheDomainThisInstallationWouldPush(): void
    {
        self::assertSame('https://www.example.com', $this->createServiceWithFixtureKeyPair([])->getPushDomain());
        self::assertNull(
            $this->createServiceWithFixtureKeyPair([], trustedDomain: null)->getPushDomain(),
            'a domain that could only be guessed is no domain'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function settledStatusProvider(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'revoked' => ['revoked'],
        ];
    }

    /**
     * The assistant's "Try again" forces a push past the settled-and-fresh short-circuit, but a
     * click storm must not become a push storm: a success younger than a minute is not repeated.
     *
     * @test
     * @dataProvider settledStatusProvider
     */
    public function aForcedPushIsSkippedWithinAMinuteOfASuccessfulPushAndRunsAfterIt(string $status): void
    {
        $response = $status === 'revoked' ? $this->revokedResponse() : $this->confirmedResponse();
        $service = $this->createServiceWithFixtureKeyPair([$response, $response]);
        self::assertNotNull($service->pushIfNecessary());
        self::assertSame($status, $service->getPushStatus()['status']);

        self::assertNull($service->pushIfNecessary(force: true), 'a fresh success throttles the forced push');
        self::assertCount(1, $this->requestHistory);

        $this->agePushStateBySeconds(61);
        self::assertNull($service->pushIfNecessary(), 'unforced, a confirmation younger than a day still stops the push');
        self::assertNotNull($service->pushIfNecessary(force: true), 'forced, a success older than a minute is re-pushed');
        self::assertCount(2, $this->requestHistory);
        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([2, 2], $service->requestedTimeouts, 'a forced push stays on the authorization budget');
        self::assertNull($service->pushIfNecessary(force: true), 'the successful forced push refreshed the timestamp');
        self::assertCount(2, $this->requestHistory);
    }

    /**
     * A push refused because the backend cannot verify the chain (it never learned, or has
     * revoked, the live key) is recovered by announcing the live key unchained once and chaining
     * the pending key to it again - three round trips, and the re-enrolment the announcement may
     * have caused survives the pending key's own record.
     *
     * @test
     */
    public function anUnverifiableChainIsRecoveredByAnnouncingTheLiveKeyAndChainingOnceMore(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->unverifiableChainResponse(),
            $this->confirmedResponse('root-kid-2'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();

        $result = $service->rotateKeyPair();

        self::assertTrue($result->successful, (string)$result->errorMessage);
        self::assertCount(4, $this->requestHistory);
        $refused = json_decode((string)$this->requestHistory[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $announcement = json_decode((string)$this->requestHistory[2]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::FIXTURE_KID, $announcement['kid'], 'the LIVE key is announced, never the successor');
        self::assertSame(trim($this->fixturePublicKeyPem()), $announcement['public_key_pem']);
        self::assertNull($announcement['chain_kid']);
        self::assertNull($announcement['chain_signature']);
        self::assertSame($refused, $this->lastRequestBody(), 'the pending key is re-sent exactly as refused, chained to the live key');
        self::assertSame(self::FIXTURE_KID, $this->lastRequestBody()['chain_kid']);
        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([2, 15, 15, 15], $service->requestedTimeouts, 'the announcement runs on the pending push\'s timeout');

        $keyPairService = $this->createKeyPairService();
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair is promoted');
        self::assertSame($this->lastRequestBody()['kid'], $keyPairService->getKeyId());
        self::assertSame('root-kid-2', $this->recordedPushState()['install_root_kid']);
        self::assertSame('reenrolled', $service->getPushStatus()['status'], 'the root change the announcement caused is kept by the second record');
        self::assertTrue($service->isKeyConfirmed());
    }

    /**
     * The announcement of a revoked lineage records `revoked` - the module offers re-enrolment on
     * it - but the rotation itself is reported as what it was: refused.
     *
     * @test
     */
    public function anUnverifiableChainStopsAfterTheLiveKeyIsReportedRevoked(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->unverifiableChainResponse(),
            $this->revokedResponse('root-kid-1'),
        ]);
        $service->push();

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful, 'the original refusal is reported, not the announcement\'s answer');
        self::assertSame('chain_unverifiable', $result->rejectionReason);
        self::assertCount(3, $this->requestHistory, 'no chained re-send to a revoked lineage');
        $keyPairService = $this->createKeyPairService();
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'the pending pair waits');
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is untouched');
        self::assertSame('revoked', $this->recordedPushState()['status']);
        self::assertSame('revoked', $service->getPushStatus()['status'], 'persistent, so the module offers re-enrolment');
        self::assertFalse($service->isKeyConfirmed());
    }

    /** @test */
    public function theRecoveryFromAnUnverifiableChainRunsAtMostOncePerPush(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->unverifiableChainResponse(),
            $this->confirmedResponse('root-kid-1'),
            $this->unverifiableChainResponse(),
            $this->confirmedResponse('root-kid-1'),
        ]);
        $service->push();

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertSame('chain_unverifiable', $result->rejectionReason);
        self::assertCount(4, $this->requestHistory, 'refused, announced, refused again: stop');
        self::assertTrue($this->createKeyPairService()->hasPendingKeyPair());
        self::assertSame('confirmed', $service->getPushStatus()['status'], 'the announcement of an unchanged root is not a re-enrolment');
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function refusalWithoutRecoveryProvider(): array
    {
        return [
            'chain_domain_mismatch, the clone guard, is a hard stop' => [
                new Response(422, ['Content-Type' => 'application/json'], '{"error":"Signing key rejected.","reason":"chain_domain_mismatch"}'),
                'chain_domain_mismatch',
            ],
            'a transport failure carries no reason' => [
                new ConnectException('Connection timed out', new Request('POST', 'https://api.neosidekick.test')),
                null,
            ],
            'a refusal without a reason' => [new Response(503, [], 'maintenance'), null],
        ];
    }

    /**
     * @test
     * @dataProvider refusalWithoutRecoveryProvider
     */
    public function onlyAChainUnverifiableRefusalTriggersTheRecovery(mixed $answer, ?string $expectedReason): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $answer,
            $this->confirmedResponse('root-kid-1'),
        ]);
        $service->push();

        $result = $service->rotateKeyPair();

        self::assertFalse($result->successful);
        self::assertSame($expectedReason, $result->rejectionReason);
        self::assertCount(2, $this->requestHistory, 'no announcement of the live key');
        self::assertTrue($this->createKeyPairService()->hasPendingKeyPair());
    }

    /**
     * Unattended (the editor's authorization, the embed handshake) the recovery is split: the live
     * key is announced in the same call, but the pending key is not re-sent - it chains on its
     * next due retry, which the announcement made verifiable. Two 2 s round trips at most.
     *
     * @test
     */
    public function theUnattendedPathAnnouncesTheLiveKeyAndLeavesThePendingKeyToItsNextRetry(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->unverifiableChainResponse(),
            $this->confirmedResponse('root-kid-2'),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $service->push();
        $this->createKeyPairService()->preparePendingKeyPair();

        $result = $service->pushIfNecessary();

        self::assertNotNull($result);
        self::assertFalse($result->successful, 'the refusal is reported, not the announcement');
        self::assertSame('chain_unverifiable', $result->rejectionReason);
        self::assertCount(3, $this->requestHistory, 'refused, announced: no re-send in the same call');
        $announcement = json_decode((string)$this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::FIXTURE_KID, $announcement['kid'], 'the LIVE key is announced');
        self::assertNull($announcement['chain_kid']);
        /** @phpstan-ignore-next-line the test double records the timeouts */
        self::assertSame([2, 2, 2], $service->requestedTimeouts, 'everything stays on the authorization budget');
        self::assertTrue($this->createKeyPairService()->hasPendingKeyPair(), 'the pending pair waits');
        self::assertSame('reenrolled', $service->getPushStatus()['status'], 'the announcement recorded the root change');

        self::assertNull($service->pushIfNecessary(), 'the pending pair backs off for a minute');
        self::assertCount(3, $this->requestHistory);

        $this->agePendingPairBySeconds(120);
        $retry = $service->pushIfNecessary();

        self::assertNotNull($retry);
        self::assertTrue($retry->successful, (string)$retry->errorMessage);
        self::assertCount(4, $this->requestHistory);
        self::assertSame(self::FIXTURE_KID, $this->lastRequestBody()['chain_kid'], 'the retry chains to the now-known live key');
        $keyPairService = $this->createKeyPairService();
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair is promoted');
        self::assertSame($this->lastRequestBody()['kid'], $keyPairService->getKeyId());
        self::assertSame('confirmed', $service->getPushStatus()['status'], 'a chained retry with an unchanged root records no re-enrolment: the announcement\'s notice does not survive it');
        self::assertTrue($service->isKeyConfirmed());
    }

    /**
     * The live path flags a re-enrolment too: a routine push the same target answers with another
     * root means the backend enrolled the key anew (its row was lost), which the operator must see.
     *
     * @test
     */
    public function aLivePushAnsweredWithAnotherRootAtTheSameTargetIsRecordedAsReenrolled(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2'),
        ]);
        $service->push();
        self::assertSame('confirmed', $service->getPushStatus()['status']);
        $this->agePushStateBySeconds(90000);

        self::assertNotNull($service->pushIfNecessary());

        self::assertSame('reenrolled', $service->getPushStatus()['status']);
        self::assertTrue($this->recordedPushState()['reenrolled']);
        self::assertSame('root-kid-2', $this->recordedPushState()['install_root_kid']);
        self::assertSame($this->recordedPushState()['pushed_at'], $this->recordedPushState()['reenrolled_at']);
    }

    /** @test */
    public function aLivePushAfterATargetSwitchIsNotRecordedAsReenrolled(): void
    {
        $service = $this->createServiceWithFixtureKeyPair([
            $this->confirmedResponse('root-kid-1'),
            $this->confirmedResponse('root-kid-2'),
        ]);
        $service->push();

        $this->setProtectedProperty($service, 'externalApiDomain', 'https://other.neosidekick.test');
        self::assertNotNull($service->pushIfNecessary());

        self::assertSame('confirmed', $service->getPushStatus()['status'], 'the previous root belongs to another target: unknown, not changed');
        self::assertArrayNotHasKey('reenrolled', $this->recordedPushState());
        self::assertSame('root-kid-2', $this->recordedPushState()['install_root_kid']);
    }

    /**
     * The row's push columns in the map shape these assertions grew up with. The service itself
     * reads the typed getters; `reenrolled` is present only for an explicit true (NULL is the
     * column's "never written" state), and a missing `install_root_kid` is absent, not null.
     *
     * @return array<string, mixed>
     */
    private function recordedPushState(): array
    {
        $record = $this->requireRecord();
        self::assertNotNull($record->getPushKid(), 'no push was recorded');

        $state = [
            'kid' => $record->getPushKid(),
            'target' => $record->getPushTarget(),
            'status' => $record->getPushStatus(),
            'plugin_version' => $record->getPushPluginVersion(),
            'pushed_at' => $record->getPushedAt()?->format(DATE_ATOM),
        ];
        if ($record->getPushInstallRootKid() !== null) {
            $state['install_root_kid'] = $record->getPushInstallRootKid();
        }
        if ($record->getPushReenrolled() === true) {
            $state['reenrolled'] = true;
            $state['reenrolled_at'] = ($record->getPushReenrolledAt() ?? $record->getPushedAt())?->format(DATE_ATOM);
        }

        return $state;
    }

    private function hasRecordedPush(): bool
    {
        return $this->repository->findInstallRecord()?->getPushKid() !== null;
    }

    private function removeRecordedPluginVersion(): void
    {
        $this->setRecordColumn('pushPluginVersion', null);
    }

    private function agePushStateBySeconds(int $seconds): void
    {
        $this->setRecordColumn('pushedAt', new DateTimeImmutable('@' . (time() - $seconds)));
    }

    private function agePendingPairBySeconds(int $seconds): void
    {
        $this->setRecordColumn('pendingRetriedAt', new DateTimeImmutable('@' . (time() - $seconds)));
    }

    private function requireRecord(): AgentSigningKeyRecord
    {
        $record = $this->repository->findInstallRecord();
        self::assertNotNull($record, 'the installation has no signing-key row');

        return $record;
    }

    /**
     * The entity has no setters - only the database writes its columns; the fake repository IS
     * the database here, so the test writes them the same way it does.
     */
    private function setRecordColumn(string $propertyName, mixed $value): void
    {
        $property = new ReflectionProperty(AgentSigningKeyRecord::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($this->requireRecord(), $value);
    }

    private function pendingResponse(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'pending',
            'kid' => self::FIXTURE_KID,
            'fingerprint' => self::FIXTURE_FINGERPRINT,
        ], JSON_THROW_ON_ERROR));
    }

    private function revokedResponse(?string $installRootKid = null): Response
    {
        $body = [
            'status' => 'revoked',
            'kid' => self::FIXTURE_KID,
            'fingerprint' => self::FIXTURE_FINGERPRINT,
        ];
        if ($installRootKid !== null) {
            $body['install_root_kid'] = $installRootKid;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * The backend's refusal of a chain it cannot verify, with the prose old plugins show verbatim.
     */
    private function unverifiableChainResponse(): Response
    {
        return new Response(422, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'Signing key rejected.',
            'reason' => 'chain_unverifiable',
            'message' => 'NEOSidekick could not verify that the new key belongs to this installation\'s current key.',
        ], JSON_THROW_ON_ERROR));
    }

    private function confirmedResponse(?string $installRootKid = null, string $kid = self::FIXTURE_KID, ?string $registeredDomain = null): Response
    {
        $body = [
            'status' => 'confirmed',
            'kid' => $kid,
            'fingerprint' => self::FIXTURE_FINGERPRINT,
        ];
        if ($installRootKid !== null) {
            $body['install_root_kid'] = $installRootKid;
        }
        if ($registeredDomain !== null) {
            $body['domain'] = $registeredDomain;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, mixed> $queuedResponses
     */
    private function createServiceWithFixtureKeyPair(
        array $queuedResponses,
        string $pluginVersion = '1.2.3',
        string $externalApiDomain = 'https://api.neosidekick.test',
        ?LoggerInterface $logger = null,
        ?string $trustedDomain = 'https://www.example.com'
    ): AgentSigningKeyPushService {
        $this->repository->seed(new AgentSigningKeyRecord($this->fixturePrivateKeyPem(), $this->fixturePublicKeyPem()));

        return $this->createService($queuedResponses, $pluginVersion, $externalApiDomain, $logger, $trustedDomain);
    }

    /**
     * @param array<int, mixed> $queuedResponses
     */
    private function createService(
        array $queuedResponses,
        string $pluginVersion = '1.2.3',
        string $externalApiDomain = 'https://api.neosidekick.test',
        ?LoggerInterface $logger = null,
        ?string $trustedDomain = 'https://www.example.com'
    ): AgentSigningKeyPushService {
        $handlerStack = HandlerStack::create(new MockHandler($queuedResponses));
        $handlerStack->push(Middleware::history($this->requestHistory));

        $helper = $this->createInternalHelper($pluginVersion, $trustedDomain);

        $service = new class () extends AgentSigningKeyPushService {
            public ?Client $pushClient = null;

            /**
             * @var array<int, int>
             */
            public array $requestedTimeouts = [];

            protected function createPushClient(int $timeoutSeconds = 2): Client
            {
                $this->requestedTimeouts[] = $timeoutSeconds;

                return $this->pushClient ?? parent::createPushClient($timeoutSeconds);
            }
        };
        $service->pushClient = new Client(['handler' => $handlerStack]);

        $this->setProtectedProperty($service, 'agentKeyPairService', $this->createKeyPairService());
        $this->setProtectedProperty($service, 'agentSigningKeyRecordRepository', $this->repository);
        $this->setProtectedProperty($service, 'neosidekickInternalHelper', $helper);
        $this->setProtectedProperty($service, 'logger', $logger ?? $this->createMock(LoggerInterface::class));
        $this->setProtectedProperty($service, 'apiKey', 'test-api-key');
        $this->setProtectedProperty($service, 'externalApiDomain', $externalApiDomain);

        return $service;
    }

    private function createInternalHelper(string $pluginVersion, ?string $trustedDomain = 'https://www.example.com'): NEOSidekickInternalHelper
    {
        $helper = $this->createMock(NEOSidekickInternalHelper::class);
        $helper->method('domain')->willReturn($trustedDomain ?? 'http://localhost');
        $helper->method('resolveTrustedDomain')->willReturn($trustedDomain);
        $helper->method('pluginVersion')->willReturn($pluginVersion);

        return $helper;
    }

    /**
     * A keypair service on the same row. The fake repository mutates the very entity it hands
     * out, so a no-op refresh() is all the service needs here; contains() is true because the
     * fake never detaches anything. A test that wants to observe the refresh passes its own
     * EntityManager double.
     */
    private function createKeyPairService(?EntityManagerInterface $entityManager = null): AgentKeyPairService
    {
        if ($entityManager === null) {
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->method('contains')->willReturn(true);
        }

        $keyPairService = new AgentKeyPairService();
        $this->setProtectedProperty($keyPairService, 'agentSigningKeyRecordRepository', $this->repository);
        $this->setProtectedProperty($keyPairService, 'entityManager', $entityManager);
        $this->setProtectedProperty($keyPairService, 'logger', $this->createMock(LoggerInterface::class));

        return $keyPairService;
    }

    /**
     * A keypair service on the same row whose private key cannot be read because the storage
     * fails - the one failure the chain block must not mistake for an unusable key.
     */
    private function createKeyPairServiceFailingToReadThePrivateKey(): AgentKeyPairService
    {
        $keyPairService = new class () extends AgentKeyPairService {
            public function getPrivateKeyPem(): string
            {
                throw new AgentSigningKeyStorageException('The agent signing keypair could not be loaded from the database.', 1757000012);
            }
        };
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('contains')->willReturn(true);
        $this->setProtectedProperty($keyPairService, 'agentSigningKeyRecordRepository', $this->repository);
        $this->setProtectedProperty($keyPairService, 'entityManager', $entityManager);
        $this->setProtectedProperty($keyPairService, 'logger', $this->createMock(LoggerInterface::class));

        return $keyPairService;
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

    private function lastRequest(): Request
    {
        self::assertNotEmpty($this->requestHistory);
        $lastEntry = $this->requestHistory[count($this->requestHistory) - 1];

        return $lastEntry['request'];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequestBody(): array
    {
        return json_decode((string)$this->lastRequest()->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function fixturePublicKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem');
    }

    private function fixturePrivateKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pem');
    }

    private function rotatedPublicKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem');
    }

    private function rotatedPrivateKeyPem(): string
    {
        return (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pem');
    }
}

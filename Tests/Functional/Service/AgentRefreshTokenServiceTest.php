<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use DateTimeImmutable;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Flow\Utility\Algorithms;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentRefreshTokenRecordRepository;
use NEOSidekick\AiAssistant\Exception\AgentRefreshTokenRejectedException;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentRefreshTokenService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use NEOSidekick\AiAssistant\Security\Aspect\AgentRefreshTokenRevocationAspect;
use Neos\Neos\Domain\Service\UserService;
use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Functional tests for the refresh credential lifecycle against real persistence:
 * issuance (fresh family per consent, prior families revoked), the refresh grant
 * (rotation with its concurrent-spend CAS, sliding window capped by the family
 * lifetime), the full positively-identified rejection taxonomy, replay-as-theft
 * family revocation, and the two revocation events driven by
 * {@see AgentRefreshTokenRevocationAspect} through their REAL entry points:
 * AuthenticationProviderManager::logout() (chat-marked families only) and
 * Neos UserService::setUserPassword() (every family of every account of the user,
 * across all consumer markers, revoked BEFORE the password change proceeds).
 */
class AgentRefreshTokenServiceTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    /**
     * Stands in for an external connection's opaque consumer id - a UUID, so it can never
     * collide with the literal 'chat' marker.
     */
    private const EXTERNAL_CONSUMER_MARKER = 'f3f0a2e1-6b1d-4d6a-9f3a-2b7c5f1e8d40';

    /**
     * The table the closed-EntityManager test reads back through raw DBAL, deliberately
     * bypassing the ORM whose EntityManager that test closes.
     */
    private const RECORD_TABLE = 'neosidekick_aiassistant_domain_model_agentrefreshtokenrecord';

    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;

    protected AgentRefreshTokenService $agentRefreshTokenService;

    protected AgentRefreshTokenRecordRepository $recordRepository;

    protected AccountRepository $accountRepository;

    protected AgentTokenService $agentTokenService;


    protected PartyService $partyService;

    protected PartyRepository $partyRepository;


    public function setUp(): void
    {
        parent::setUp();
        $this->agentRefreshTokenService = $this->objectManager->get(AgentRefreshTokenService::class);
        $this->recordRepository = $this->objectManager->get(AgentRefreshTokenRecordRepository::class);
        $this->accountRepository = $this->objectManager->get(AccountRepository::class);
        $this->agentTokenService = $this->objectManager->get(AgentTokenService::class);
        $this->partyService = $this->objectManager->get(PartyService::class);
        $this->partyRepository = $this->objectManager->get(PartyRepository::class);
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function issuingCreatesARecordStoringOnlyTheTokenHash(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);

        $before = time();
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent-1');
        $after = time();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $opaqueToken);

        $record = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));
        self::assertNotNull($record);
        self::assertSame('jti-consent-1', $record->getJti());
        self::assertSame($accountUuid, $record->getAccountUuid());
        self::assertFalse($record->isRevoked());
        self::assertNull($record->getRotatedAt());

        $slidingLifetime = AgentRefreshTokenService::REFRESH_TOKEN_SLIDING_LIFETIME;
        $familyLifetime = AgentRefreshTokenService::REFRESH_FAMILY_ABSOLUTE_LIFETIME;
        self::assertGreaterThanOrEqual($before + $slidingLifetime, $record->getRefreshExpiresAt()->getTimestamp());
        self::assertLessThanOrEqual($after + $slidingLifetime, $record->getRefreshExpiresAt()->getTimestamp());
        self::assertGreaterThanOrEqual($before + $familyLifetime, $record->getFamilyExpiresAt()->getTimestamp());
        self::assertLessThanOrEqual($after + $familyLifetime, $record->getFamilyExpiresAt()->getTimestamp());
    }

    /**
     * @test
     */
    public function aFreshConsentCreatesANewFamilyAndRevokesEveryPriorFamilyOfTheAccount(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);

        $firstToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-1');
        $secondToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-2');

        // The supersession is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        $firstRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $firstToken));
        $secondRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $secondToken));

        self::assertTrue($firstRecord->isRevoked(), 'a fresh consent must supersede the prior family');
        self::assertFalse($secondRecord->isRevoked());
        self::assertNotSame($firstRecord->getFamilyId(), $secondRecord->getFamilyId());
    }

    /**
     * @test
     */
    public function redeemingRotatesTheCredentialAndMintsAVerifiableJwtWithoutASessionClaim(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent');
        $originalRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));

        $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        self::assertSame(['jwt', 'refresh_token', 'refresh_token_expires_at'], array_keys($result));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['refresh_token']);
        self::assertNotSame($opaqueToken, $result['refresh_token']);

        $claims = $this->agentTokenService->verifyToken($result['jwt']);
        self::assertSame($account->getAccountIdentifier(), $claims['sub']);
        self::assertSame($accountUuid, $claims['account_id']);
        self::assertArrayNotHasKey('session_id', $claims);
        self::assertTrue($this->agentTokenService->isNewGenerationToken($result['jwt']));

        self::assertTrue($originalRecord->isRevoked(), 'rotation must revoke the spent record');
        self::assertNotNull($originalRecord->getRotatedAt());

        $successorRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $result['refresh_token']));
        self::assertNotNull($successorRecord);
        self::assertFalse($successorRecord->isRevoked());
        self::assertSame($originalRecord->getFamilyId(), $successorRecord->getFamilyId());
        self::assertSame($originalRecord->getAccountUuid(), $successorRecord->getAccountUuid());
        self::assertSame(
            $originalRecord->getFamilyExpiresAt()->getTimestamp(),
            $successorRecord->getFamilyExpiresAt()->getTimestamp(),
            'the absolute family cap must never move on rotation'
        );
        self::assertSame($claims['jti'], $successorRecord->getJti(), 'the successor must be paired to the freshly minted JWT');
        self::assertSame($successorRecord->getRefreshExpiresAt()->getTimestamp(), $result['refresh_token_expires_at']);
    }

    /**
     * @test
     */
    public function theSlidingWindowOfTheSuccessorIsCappedByTheFamilyExpiry(): void
    {
        $account = $this->createBackendAccountWithUser();
        $familyExpiresAt = new DateTimeImmutable('+10 days');
        [$opaqueToken] = $this->createRecord($account, [
            'refreshExpiresAt' => new DateTimeImmutable('+5 days'),
            'familyExpiresAt' => $familyExpiresAt,
        ]);

        $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        self::assertSame(
            $familyExpiresAt->getTimestamp(),
            $result['refresh_token_expires_at'],
            'now + 30 days lies past the family cap, so the successor window must be the cap itself'
        );
    }

    /**
     * @test
     */
    public function replayingARevokedTokenRevokesTheEntireFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $opaqueToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-consent');
        $familyId = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken))->getFamilyId();

        $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        try {
            $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
            self::fail('Expected an AgentRefreshTokenRejectedException');
        } catch (AgentRefreshTokenRejectedException $e) {
            self::assertSame(AgentRefreshTokenRejectedException::REASON_REPLAYED, $e->getReason());
        }

        // The family revocation is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        $familyRecords = $this->recordRepository->findByFamilyId($familyId)->toArray();
        self::assertCount(2, $familyRecords);
        foreach ($familyRecords as $record) {
            self::assertTrue($record->isRevoked(), 'a replay must revoke every record of the family - theft signal');
        }
    }

    /**
     * The rotation CAS: two concurrent redemptions of the same valid token both pass the
     * in-memory isRevoked() check, but only one wins the conditional revocation UPDATE.
     * Simulated here exactly at that seam: the row is revoked directly in the database
     * (as the concurrent winner would) while this process' identity-mapped record still
     * reads unrevoked - the loser must get the distinct REASON_SPENT rejection and must
     * NOT torch the family (a same-instant race can be benign; only the replay of a
     * record already revoked at check time keeps the theft response).
     *
     * @test
     */
    public function aConcurrentlySpentTokenIsRejectedAsSpentWithoutRevokingTheFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken, $record] = $this->createRecord($account);
        [, $siblingRecord] = $this->createRecord($account, ['familyId' => $record->getFamilyId()]);

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        $entityManager->createQuery(
            'UPDATE ' . AgentRefreshTokenRecord::class . ' r SET r.revoked = true WHERE r = :record'
        )->execute(['record' => $record]);
        self::assertFalse($record->isRevoked(), 'precondition: the in-memory record must not know about the concurrent spend');

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_SPENT);

        $revokedRecordsInFamily = (int)$entityManager->createQuery(
            'SELECT COUNT(r) FROM ' . AgentRefreshTokenRecord::class . ' r WHERE r.familyId = :familyId AND r.revoked = true'
        )->setParameter('familyId', $record->getFamilyId())->getSingleScalarResult();
        self::assertSame(1, $revokedRecordsInFamily, 'only the concurrently spent record may be revoked - no family torch');
        self::assertFalse($siblingRecord->isRevoked());
    }

    /**
     * @test
     */
    public function anExpiredSlidingWindowIsRejected(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account, [
            'refreshExpiresAt' => new DateTimeImmutable('-1 hour'),
            'familyExpiresAt' => new DateTimeImmutable('+60 days'),
        ]);

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_WINDOW_EXPIRED);
    }

    /**
     * @test
     */
    public function anExceededFamilyCapIsRejected(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account, [
            'refreshExpiresAt' => new DateTimeImmutable('+5 days'),
            'familyExpiresAt' => new DateTimeImmutable('-1 hour'),
        ]);

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_FAMILY_EXPIRED);
    }

    /**
     * @test
     */
    public function anUnknownTokenIsRejected(): void
    {
        $this->expectRejection(bin2hex(random_bytes(32)), AgentRefreshTokenRejectedException::REASON_UNKNOWN);
    }

    /**
     * @test
     */
    public function aMissingOrMalformedTokenIsRejected(): void
    {
        $this->expectRejection(null, AgentRefreshTokenRejectedException::REASON_MALFORMED);
        $this->expectRejection('', AgentRefreshTokenRejectedException::REASON_MALFORMED);
        $this->expectRejection('not-a-refresh-token', AgentRefreshTokenRejectedException::REASON_MALFORMED);
        $this->expectRejection(strtoupper(bin2hex(random_bytes(32))), AgentRefreshTokenRejectedException::REASON_MALFORMED);
    }

    /**
     * @test
     */
    public function aDeletedAccountIsRejected(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account);

        $this->accountRepository->remove($account);
        $this->persistenceManager->persistAll();
        // Detach the identity map so the lookup hits the database like a fresh request would;
        // without this the just-removed account is still resolvable in this very process.
        $this->persistenceManager->clearState();

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_ACCOUNT_NOT_FOUND);
    }

    /**
     * @test
     */
    public function aDeactivatedAccountIsRejected(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account);

        $account->setExpirationDate(new DateTime('-1 day'));
        $this->accountRepository->update($account);
        $this->persistenceManager->persistAll();

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_ACCOUNT_INACTIVE);
    }

    /**
     * @test
     */
    public function anAccountWithoutAnAssignedUserIsRejected(): void
    {
        $account = $this->createBackendAccount();
        [$opaqueToken, $record] = $this->createRecord($account);

        $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_ACCOUNT_UNUSABLE);

        self::assertFalse($record->isRevoked(), 'an unusable account must be rejected before the credential is spent');
        self::assertNull($record->getRotatedAt());
    }

    /**
     * The compare-and-swap that spends the credential commits immediately, so it must run
     * only AFTER the (side-effect-free) mint succeeded: a transient minting failure that
     * burns the token would force the editor through a fresh consent for nothing.
     *
     * @test
     */
    public function aTransientMintingFailureDoesNotSpendTheCredential(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken, $record] = $this->createRecord($account);

        $failingTokenService = $this->getMockBuilder(AgentTokenService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateTokenDataForAccount'])
            ->getMock();
        $failingTokenService->method('generateTokenDataForAccount')
            ->willThrowException(new AgentTokenException('signing key unreadable', 500));

        $originalTokenService = $this->replaceTokenService($failingTokenService);
        try {
            $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
            self::fail('Expected the minting failure to escape as an AgentTokenException');
        } catch (AgentTokenException $e) {
            self::assertSame(500, $e->getStatusCode());
        } finally {
            $this->replaceTokenService($originalTokenService);
        }

        self::assertFalse($record->isRevoked(), 'a failed mint must never burn the credential');
        self::assertNull($record->getRotatedAt());

        $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['refresh_token']);
    }

    /**
     * A token revoked WITHOUT ever having been rotated away is an explicit logout (or a
     * supersession by a fresh consent), not a theft signal: it is rejected with its own
     * reason, the family stays intact and nothing is logged as a security event.
     *
     * @test
     */
    public function aTokenRevokedByLogoutIsRejectedWithoutTorchingTheFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken, $record] = $this->createRecord($account);
        [, $siblingRecord] = $this->createRecord($account, ['familyId' => $record->getFamilyId()]);

        $record->revoke();
        $this->recordRepository->update($record);
        $this->persistenceManager->persistAll();
        self::assertNull($record->getRotatedAt(), 'precondition: a logout revocation never stamps rotatedAt');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $originalLogger = $this->replaceLogger($logger);
        try {
            $this->expectRejection($opaqueToken, AgentRefreshTokenRejectedException::REASON_REVOKED);
        } finally {
            $this->replaceLogger($originalLogger);
        }

        self::assertFalse($siblingRecord->isRevoked(), 'a logout revocation must never torch the rest of the family');
    }

    /**
     * The storage-readiness probe is what keeps an installation that never ran
     * `./flow doctrine:migrate` on the legacy path instead of minting a refresh family it
     * can never persist.
     *
     * @test
     */
    public function theStorageProbeAnswersTrueOnAMigratedInstallAndFalseWhenTheTableIsUnavailable(): void
    {
        self::assertTrue($this->agentRefreshTokenService->isStorageReady());

        $failingRepository = $this->createMock(AgentRefreshTokenRecordRepository::class);
        $failingRepository->method('countAll')
            ->willThrowException(new \RuntimeException('Base table or view not found: agentrefreshtokenrecord'));

        $originalRepository = $this->replaceRecordRepository($failingRepository);
        try {
            self::assertFalse($this->agentRefreshTokenService->isStorageReady());
        } finally {
            $this->replaceRecordRepository($originalRepository);
        }
    }

    /**
     * The identity is pinned in the record: no rejection reason ever derives from caller
     * input, so a valid token of account A can never mint for account B - pinned by
     * asserting the minted claims against the record's account.
     *
     * @test
     */
    public function theMintedIdentityComesFromTheRecordOnly(): void
    {
        $accountA = $this->createBackendAccountWithUser();
        $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($accountA);

        $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
        $claims = $this->agentTokenService->verifyToken($result['jwt']);

        self::assertSame($accountA->getAccountIdentifier(), $claims['sub']);
        self::assertSame($this->persistenceManager->getIdentifierByObject($accountA), $claims['account_id']);
    }

    /**
     * The REAL logout path, end to end: AuthenticationProviderManager::logout() sets
     * every token to NO_CREDENTIALS_GIVEN BEFORE any post-logout hook can run - a signal
     * slot reading accounts off the tokens therefore always saw nothing and revoked
     * nothing. The revocation is now an around aspect capturing the accounts BEFORE the
     * de-authentication; this test goes through logout() itself, so it fails on any
     * wiring that only works with still-authenticated tokens.
     *
     * @test
     */
    public function anExplicitLogoutRevokesAllRecordsOfTheLoggedOutAccountAcrossAllFamilies(): void
    {
        $account = $this->createBackendAccountWithUser();
        $otherAccount = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $otherAccountUuid = $this->persistenceManager->getIdentifierByObject($otherAccount);

        [$firstOpaqueToken] = $this->createRecord($account);
        [$secondOpaqueToken] = $this->createRecord($account);
        [$otherOpaqueToken] = $this->createRecord($otherAccount);

        $this->authenticateAccount($account);
        $this->authenticationManager->logout();

        // The logout revocation is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        foreach ([$firstOpaqueToken, $secondOpaqueToken] as $opaqueToken) {
            $record = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));
            self::assertNotNull($record);
            self::assertTrue($record->isRevoked(), 'logout must revoke every record of the account');
        }

        $otherRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $otherOpaqueToken));
        self::assertFalse($otherRecord->isRevoked(), 'other accounts must stay untouched');
        self::assertSame($otherAccountUuid, $otherRecord->getAccountUuid());
    }

    /**
     * The consumer marker scopes the logout revocation: a Neos logout ends the Neos chat
     * session, so it may only reach the chat-marked families. An external consumer's
     * credential chain is not bound to the editor's backend session and must survive it.
     *
     * @test
     */
    public function logoutRevokesOnlyChatMarkedFamilies(): void
    {
        $account = $this->createBackendAccountWithUser();

        [$chatToken] = $this->createRecord($account);
        [$externalToken] = $this->createRecord($account, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);

        $this->authenticateAccount($account);
        $this->authenticationManager->logout();

        // The logout revocation is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        $chatRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $chatToken));
        $externalRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $externalToken));

        self::assertTrue($chatRecord->isRevoked(), 'logout must revoke the chat-marked family');
        self::assertFalse($externalRecord->isRevoked(), 'logout must never revoke another consumer\'s family');
    }

    /**
     * The REAL password-change path: Neos\Neos\Domain\Service\UserService::
     * setUserPassword() is the single choke point every caller goes through (self-service
     * settings, admin reset, CLI), and the around advice must revoke through the join
     * point's `user` argument - never through the security context, which holds the ADMIN
     * on an admin reset and nobody at all on the CLI. Nothing is authenticated here, so a
     * context-reading implementation revokes nothing and this test fails.
     *
     * Scope pinned three ways: EVERY account of the user (a Neos user may own more than
     * one), EVERY consumer marker (a password change means the credentials may have
     * leaked, so an external connection's chain dies with the chat one - unlike logout),
     * and no other user's records.
     *
     * Ordering pinned by observing the account's credentialsSource at the moment the
     * revocation runs: setUserPassword() re-hashes it while proceeding, so seeing the OLD
     * value proves the revocation ran BEFORE proceed(). Revoke-first is the fail-closed
     * ordering - setUserPassword() destroys the user's sessions before it re-hashes, so a
     * proceed-first advice would protect nothing if the change threw.
     *
     * @test
     */
    public function aPasswordChangeRevokesEveryRecordOfEveryAccountOfTheUserAcrossAllConsumerMarkers(): void
    {
        $user = new User();
        $user->setName(new PersonName('', 'Password', '', 'Changer'));
        $this->partyRepository->add($user);
        $firstAccount = $this->createBackendAccount();
        $secondAccount = $this->createBackendAccount();
        $this->partyService->assignAccountToParty($firstAccount, $user);
        $this->partyService->assignAccountToParty($secondAccount, $user);
        $unrelatedAccount = $this->createBackendAccountWithUser();
        $this->persistenceManager->persistAll();

        [$chatToken] = $this->createRecord($firstAccount);
        [$externalToken] = $this->createRecord($firstAccount, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);
        [$secondAccountToken] = $this->createRecord($secondAccount);
        [$unrelatedToken] = $this->createRecord($unrelatedAccount);

        $credentialsSourceBefore = 'the-old-password-hash';
        $firstAccount->setCredentialsSource($credentialsSourceBefore);
        $this->accountRepository->update($firstAccount);
        $this->persistenceManager->persistAll();

        $observedCredentialsSource = null;
        $observedAccountUuids = [];
        $realService = $this->agentRefreshTokenService;
        $revocationSpy = $this->createMock(AgentRefreshTokenService::class);
        $revocationSpy->method('revokeAllRefreshTokensOfAccounts')
            ->willReturnCallback(function (array $accountUuids) use ($realService, $firstAccount, &$observedCredentialsSource, &$observedAccountUuids) {
                $observedAccountUuids = $accountUuids;
                $observedCredentialsSource = $firstAccount->getCredentialsSource();
                $realService->revokeAllRefreshTokensOfAccounts($accountUuids);
            });

        $originalService = $this->replaceAspectService($revocationSpy);
        try {
            $this->objectManager->get(UserService::class)->setUserPassword($user, 'a-brand-new-password');
        } finally {
            $this->replaceAspectService($originalService);
        }

        self::assertCount(2, $observedAccountUuids, 'both accounts of the user must be handed to the revocation');
        self::assertContains($this->persistenceManager->getIdentifierByObject($firstAccount), $observedAccountUuids);
        self::assertContains($this->persistenceManager->getIdentifierByObject($secondAccount), $observedAccountUuids);
        self::assertNotSame(
            $credentialsSourceBefore,
            $firstAccount->getCredentialsSource(),
            'precondition of the ordering assertion: proceeding really does re-hash the credentials'
        );
        self::assertSame(
            $credentialsSourceBefore,
            $observedCredentialsSource,
            'the revocation must run BEFORE the password change proceeds'
        );

        // The revocation is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        $chatRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $chatToken));
        $externalRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $externalToken));
        $secondAccountRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $secondAccountToken));
        $unrelatedRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $unrelatedToken));

        self::assertTrue($chatRecord->isRevoked(), 'a password change must revoke the chat family');
        self::assertTrue($externalRecord->isRevoked(), 'a password change must revoke EVERY consumer marker');
        self::assertTrue($secondAccountRecord->isRevoked(), 'a password change must reach every account of the user');
        self::assertFalse($unrelatedRecord->isRevoked(), 'another user\'s records must stay untouched');
    }

    /**
     * A fresh consent supersedes only the families of the SAME consumer marker; another
     * consumer's chain for the same account stays valid.
     *
     * @test
     */
    public function reConsentSupersedesOnlyTheSameConnectionsFamilies(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);

        [$priorChatToken] = $this->createRecord($account);
        [$externalToken] = $this->createRecord($account, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);

        $freshChatToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-re-consent');

        // The supersession is a bulk UPDATE bypassing the identity map; re-fetch from the database.
        $this->persistenceManager->clearState();
        $priorChatRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $priorChatToken));
        $externalRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $externalToken));
        $freshChatRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $freshChatToken));

        self::assertTrue($priorChatRecord->isRevoked(), 'a fresh chat consent supersedes the prior chat family');
        self::assertFalse($externalRecord->isRevoked(), 'a fresh chat consent must leave another consumer untouched');
        self::assertFalse($freshChatRecord->isRevoked());
        self::assertSame(AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT, $freshChatRecord->getConsumerMarker());
    }

    /**
     * Every WP0 commit site leaves the marker at its default: a callback that carried no
     * marker mints a chat family, permanently.
     *
     * @test
     */
    public function aCommitWithoutAnExplicitMarkerCreatesAChatMarkedFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);

        $opaqueToken = $this->agentRefreshTokenService->generateOpaqueRefreshToken();
        $this->agentRefreshTokenService->commitRefreshTokenForNewFamily($opaqueToken, $accountUuid, 'jti-unmarked');

        $record = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));
        self::assertNotNull($record);
        self::assertSame(AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT, $record->getConsumerMarker());
    }

    /**
     * A family is single-consumer by construction: rotation copies the predecessor's
     * marker onto the successor. The marker is hand-set on the predecessor here, since no
     * producer of a non-chat marker exists plugin-side until WP2.
     *
     * @test
     */
    public function aRotatedSuccessorInheritsTheConsumerMarker(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken, $record] = $this->createRecord($account, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);

        $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);

        $successorRecord = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $result['refresh_token']));
        self::assertNotNull($successorRecord);
        self::assertSame(self::EXTERNAL_CONSUMER_MARKER, $successorRecord->getConsumerMarker());
        self::assertSame($record->getFamilyId(), $successorRecord->getFamilyId());
    }

    /**
     * @test
     */
    public function aLogoutWithoutAnAuthenticatedAccountRevokesNothingAndNeverThrows(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account);

        $this->authenticationManager->logout();
        $this->agentRefreshTokenService->revokeRefreshTokensOfAccounts([]);

        $record = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));
        self::assertFalse($record->isRevoked(), 'no account was logged out, so nothing may be revoked');
    }

    /**
     * A closed EntityManager cannot flush the successor record, while the predecessor's
     * CAS revocation is a DQL UPDATE that would commit on its own - burning the credential
     * and leaving the family with zero unrevoked records. The guard must refuse BEFORE the
     * transaction opens, and refuse with an infrastructure error rather than an
     * AgentRefreshTokenRejectedException: a closed EntityManager says nothing about the
     * presented credential, so the caller must surface it as a marker-less 500 the backend
     * treats as inconclusive, never as the 401 that invalidates the stored refresh state.
     *
     * @test
     */
    public function redeemingRefusesOnAClosedEntityManagerWithoutRunningTheCas(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken, $record] = $this->createRecord($account);
        $recordIdentifier = $this->persistenceManager->getIdentifierByObject($record);

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        $entityManager->close();

        $caughtException = null;
        try {
            $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $revokedFlag = $entityManager->getConnection()->fetchOne(
            'SELECT revoked FROM ' . self::RECORD_TABLE . ' WHERE persistence_object_identifier = ?',
            [$recordIdentifier]
        );
        $this->reopenEntityManager($entityManager);

        self::assertInstanceOf(RuntimeException::class, $caughtException, 'the closed EntityManager must be refused');
        self::assertNotInstanceOf(
            AgentRefreshTokenRejectedException::class,
            $caughtException,
            'a closed EntityManager is inconclusive infrastructure, never a positively identified rejection'
        );
        self::assertStringContainsString('EntityManager is closed', $caughtException->getMessage());
        self::assertSame(0, (int)$revokedFlag, 'the compare-and-swap must never have run');
    }

    /**
     * The commit phase has the mirror-image hazard of the rotation, only worse: Flow's
     * persistAll() SILENTLY no-ops on a closed EntityManager while the DQL that revokes the
     * prior families does not - so without the guard the supersession would commit alone,
     * with no successor record and no exception at all, and the editor would lose every
     * working session for a credential that was never stored. The guard must refuse before
     * the transaction opens, leaving the prior family untouched.
     *
     * @test
     */
    public function committingANewFamilyIsRefusedWhenTheEntityManagerIsClosed(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        [, $priorRecord] = $this->createRecord($account);
        $priorFamilyId = $priorRecord->getFamilyId();

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        $entityManager->close();

        $caughtException = null;
        try {
            $this->agentRefreshTokenService->commitRefreshTokenForNewFamily(
                bin2hex(random_bytes(32)),
                $accountUuid,
                'jti-closed-entity-manager'
            );
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $unrevokedPriorRecords = (int)$entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::RECORD_TABLE . ' WHERE familyid = ? AND revoked = 0',
            [$priorFamilyId]
        );
        $this->reopenEntityManager($entityManager);

        self::assertInstanceOf(RuntimeException::class, $caughtException, 'the closed EntityManager must be refused');
        self::assertStringContainsString('EntityManager is closed', $caughtException->getMessage());
        self::assertSame(1, $unrevokedPriorRecords, 'the supersession must never have run on its own');
    }

    /**
     * The inbound revoke grant: possession of a family's current refresh secret ends the
     * WHOLE family, not just the record that was presented.
     *
     * @test
     */
    public function revokingByACurrentTokenRevokesTheEntireFamily(): void
    {
        $account = $this->createBackendAccountWithUser();
        $familyId = Algorithms::generateUUID();
        [$currentToken] = $this->createRecord($account, ['familyId' => $familyId]);
        $this->createRecord($account, ['familyId' => $familyId]);

        self::assertSame(2, $this->countUnrevokedRecordsOfFamily($familyId));

        $this->agentRefreshTokenService->revokeFamilyOfRefreshToken($currentToken);

        self::assertSame(0, $this->countUnrevokedRecordsOfFamily($familyId), 'every record of the family must be revoked');
    }

    /**
     * The caller may well hold a token that already lost a rotation race - a SPENT
     * predecessor. It still names its family, so it must still end it; anything else
     * would leave a revoked connection with a live successor the caller never saw.
     *
     * @test
     */
    public function revokingByASpentTokenOfTheSameFamilyRevokesTheFamilyToo(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $spentToken = $this->agentRefreshTokenService->issueRefreshTokenForNewFamily($accountUuid, 'jti-revoke-spent');
        $familyId = $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $spentToken))->getFamilyId();
        $this->agentRefreshTokenService->redeemRefreshToken($spentToken);

        self::assertSame(1, $this->countUnrevokedRecordsOfFamily($familyId), 'the rotation leaves exactly the successor live');

        $this->agentRefreshTokenService->revokeFamilyOfRefreshToken($spentToken);

        self::assertSame(0, $this->countUnrevokedRecordsOfFamily($familyId), 'the successor must die with the family');
    }

    /**
     * An unknown token names no family, so it can revoke nothing - and must not throw
     * either, because the endpoint in front of this answers it exactly like a known one.
     *
     * @test
     */
    public function revokingByAnUnknownTokenIsASilentNoOp(): void
    {
        $account = $this->createBackendAccountWithUser();
        [, $record] = $this->createRecord($account);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $originalLogger = $this->replaceLogger($logger);
        try {
            $this->agentRefreshTokenService->revokeFamilyOfRefreshToken(bin2hex(random_bytes(32)));
        } finally {
            $this->replaceLogger($originalLogger);
        }

        self::assertSame(1, $this->countUnrevokedRecordsOfFamily($record->getFamilyId()));
    }

    /**
     * The blast radius is exactly one family: another family of the same account, and a
     * family of another consumer, both survive.
     *
     * @test
     */
    public function revokingLeavesOtherFamiliesAndOtherConsumersUntouched(): void
    {
        $account = $this->createBackendAccountWithUser();
        $targetFamilyId = Algorithms::generateUUID();
        $otherChatFamilyId = Algorithms::generateUUID();
        $externalFamilyId = Algorithms::generateUUID();
        [$targetToken] = $this->createRecord($account, ['familyId' => $targetFamilyId]);
        $this->createRecord($account, ['familyId' => $otherChatFamilyId]);
        $this->createRecord($account, [
            'familyId' => $externalFamilyId,
            'consumerMarker' => self::EXTERNAL_CONSUMER_MARKER,
        ]);

        $this->agentRefreshTokenService->revokeFamilyOfRefreshToken($targetToken);

        self::assertSame(0, $this->countUnrevokedRecordsOfFamily($targetFamilyId));
        self::assertSame(1, $this->countUnrevokedRecordsOfFamily($otherChatFamilyId), 'another family of the same account must survive');
        self::assertSame(1, $this->countUnrevokedRecordsOfFamily($externalFamilyId), 'another consumer\'s family must survive');
    }

    /**
     * On an install that never ran `./flow doctrine:migrate` there is nothing to revoke
     * and no table to ask, so the grant must fall through silently instead of turning the
     * public endpoint into a 500.
     *
     * @test
     */
    public function revokingIsANoOpWhenTheRefreshStorageIsNotReady(): void
    {
        $failingRepository = $this->createMock(AgentRefreshTokenRecordRepository::class);
        $failingRepository->method('countAll')
            ->willThrowException(new RuntimeException('Base table or view not found: agentrefreshtokenrecord'));
        $failingRepository->expects(self::never())->method('findOneByRefreshTokenHash');
        $failingRepository->expects(self::never())->method('revokeFamilyRecords');

        $originalRepository = $this->replaceRecordRepository($failingRepository);
        try {
            $this->agentRefreshTokenService->revokeFamilyOfRefreshToken(bin2hex(random_bytes(32)));
        } finally {
            $this->replaceRecordRepository($originalRepository);
        }
    }

    /**
     * The marker-scoped logout revocation is ONE bulk statement now: it must flip exactly
     * the account's unrevoked chat rows - counting neither the already-revoked rows nor
     * another consumer's - and the count it reports is the number of rows it really
     * flipped, since that count is all the log line and the callers ever see.
     *
     * @test
     */
    public function aMarkerScopedLogoutRevocationCountsAndFlipsOnlyThatMarkersUnrevokedRows(): void
    {
        $account = $this->createBackendAccountWithUser();

        [$liveChatToken] = $this->createRecord($account);
        [$alreadyRevokedChatToken] = $this->createRecord($account, ['revoked' => true]);
        [$externalToken] = $this->createRecord($account, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message) use (&$loggedMessages) {
            $loggedMessages[] = $message;
        });

        $originalLogger = $this->replaceLogger($logger);
        try {
            $this->authenticateAccount($account);
            $this->authenticationManager->logout();
        } finally {
            $this->replaceLogger($originalLogger);
        }

        $logoutMessages = array_values(array_filter($loggedMessages, static function (string $message) {
            return strpos($message, 'on explicit logout') !== false;
        }));
        self::assertCount(1, $logoutMessages);
        self::assertStringContainsString(
            'revoked 1 chat refresh token record(s)',
            $logoutMessages[0],
            'only the one unrevoked chat row may be counted'
        );

        $this->persistenceManager->clearState();
        self::assertTrue($this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $liveChatToken))->isRevoked());
        self::assertTrue($this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $alreadyRevokedChatToken))->isRevoked());
        self::assertFalse(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $externalToken))->isRevoked(),
            'the marker predicate must keep another consumer\'s rows out of the statement'
        );
    }

    /**
     * The password-change revocation passes NO marker, so its single statement must sweep
     * every consumer of the account - and count every row it flipped.
     *
     * @test
     */
    public function anUnscopedRevocationSweepsEveryConsumerMarkerOfTheAccount(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);

        [$chatToken] = $this->createRecord($account);
        [$externalToken] = $this->createRecord($account, ['consumerMarker' => self::EXTERNAL_CONSUMER_MARKER]);
        $this->createRecord($account, ['revoked' => true]);

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message) use (&$loggedMessages) {
            $loggedMessages[] = $message;
        });

        $originalLogger = $this->replaceLogger($logger);
        try {
            $this->agentRefreshTokenService->revokeAllRefreshTokensOfAccounts([$accountUuid]);
        } finally {
            $this->replaceLogger($originalLogger);
        }

        self::assertCount(1, $loggedMessages);
        self::assertStringContainsString('revoked 2 refresh token record(s) of all consumers', $loggedMessages[0]);

        $this->persistenceManager->clearState();
        self::assertTrue($this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $chatToken))->isRevoked());
        self::assertTrue(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $externalToken))->isRevoked(),
            'a password change must reach every consumer marker'
        );
    }

    /**
     * The retention prune, which is the ONLY thing that ever deletes a refresh record: it
     * runs on the rotation path, scoped to the rotating account, and only for families
     * whose expiry is older than REFRESH_RECORD_RETENTION_SECONDS - the window in which a
     * record can still be the `jti` anchor of an access token that has not expired yet.
     *
     * @test
     */
    public function aRotationPrunesOnlyTheAccountsLongExpiredFamilies(): void
    {
        $account = $this->createBackendAccountWithUser();
        $otherAccount = $this->createBackendAccountWithUser();
        $retention = AgentRefreshTokenService::REFRESH_RECORD_RETENTION_SECONDS;

        [$liveToken, $liveRecord] = $this->createRecord($account);
        [$longExpiredToken] = $this->createRecord($account, [
            'familyExpiresAt' => new DateTimeImmutable('-' . ($retention + 3600) . ' seconds'),
        ]);
        [$recentlyExpiredToken] = $this->createRecord($account, [
            'familyExpiresAt' => new DateTimeImmutable('-60 seconds'),
        ]);
        [$otherAccountToken] = $this->createRecord($otherAccount, [
            'familyExpiresAt' => new DateTimeImmutable('-' . ($retention + 3600) . ' seconds'),
        ]);

        $result = $this->agentRefreshTokenService->redeemRefreshToken($liveToken);

        $this->persistenceManager->clearState();
        self::assertNull(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $longExpiredToken)),
            'a family expired longer ago than the retention cutoff must be pruned'
        );
        self::assertNotNull(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $recentlyExpiredToken)),
            'a family expired inside the retention cutoff may still anchor a live access token'
        );
        self::assertNotNull(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $otherAccountToken)),
            'the prune is account-scoped'
        );
        self::assertNotNull(
            $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $liveToken)),
            'the rotating family must survive its own rotation'
        );
        self::assertNotNull($this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $result['refresh_token'])));
        self::assertSame($liveRecord->getFamilyId(), $this->recordRepository->findOneByRefreshTokenHash(hash('sha256', $result['refresh_token']))->getFamilyId());
    }

    /**
     * The prune is housekeeping riding on a renewal that already succeeded: it runs after
     * the rotation transaction committed, so a failing DELETE may only produce a warning -
     * never a failed renewal for a caller whose credential was rotated.
     *
     * @test
     */
    public function aFailingPruneWarnsButNeverFailsTheRotation(): void
    {
        $account = $this->createBackendAccountWithUser();
        [$opaqueToken] = $this->createRecord($account);

        $realRepository = $this->recordRepository;
        $failingRepository = $this->createMock(AgentRefreshTokenRecordRepository::class);
        $failingRepository->method('findOneByRefreshTokenHash')
            ->willReturnCallback(function (string $hash) use ($realRepository) {
                return $realRepository->findOneByRefreshTokenHash($hash);
            });
        $failingRepository->method('markSpentIfNotRevoked')
            ->willReturnCallback(function ($record, $rotatedAt) use ($realRepository) {
                return $realRepository->markSpentIfNotRevoked($record, $rotatedAt);
            });
        $failingRepository->method('add')
            ->willReturnCallback(function ($object) use ($realRepository) {
                $realRepository->add($object);
            });
        $failingRepository->method('update')
            ->willReturnCallback(function ($object) use ($realRepository) {
                $realRepository->update($object);
            });
        $failingRepository->method('deleteExpiredRecordsOfAccount')
            ->willThrowException(new RuntimeException('DELETE refused by the database'));

        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $message) use (&$warnings) {
            $warnings[] = $message;
        });

        $originalRepository = $this->replaceRecordRepository($failingRepository);
        $originalLogger = $this->replaceLogger($logger);
        try {
            $result = $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
        } finally {
            $this->replaceLogger($originalLogger);
            $this->replaceRecordRepository($originalRepository);
        }

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['refresh_token'], 'the rotation must have succeeded');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('pruning expired refresh token records failed', $warnings[0]);
        self::assertStringContainsString('DELETE refused by the database', $warnings[0]);
    }

    /**
     * The re-consent path revokes the prior families with a statement that commits on its
     * own, so it MUST stay inside one transaction with the insert of the new family:
     * otherwise a failing insert leaves the editor with every prior session dead and no
     * successor, while the platform already holds the new refresh token. Provoked here
     * through the UNIQUE `jti` index.
     *
     * @test
     */
    public function aFailingInsertOnReConsentLeavesThePriorFamiliesUnrevoked(): void
    {
        $account = $this->createBackendAccountWithUser();
        $accountUuid = $this->persistenceManager->getIdentifierByObject($account);
        $collidingJti = Algorithms::generateUUID();

        [, $priorRecord] = $this->createRecord($account);
        $this->createRecord($account, ['jti' => $collidingJti]);

        $entityManager = $this->objectManager->get(EntityManagerInterface::class);
        $caughtException = null;
        try {
            $this->agentRefreshTokenService->commitRefreshTokenForNewFamily(
                $this->agentRefreshTokenService->generateOpaqueRefreshToken(),
                $accountUuid,
                $collidingJti
            );
        } catch (\Throwable $e) {
            $caughtException = $e;
        }

        $unrevokedRecordsOfPriorFamily = (int)$entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::RECORD_TABLE . ' WHERE familyid = ? AND revoked = 0',
            [$priorRecord->getFamilyId()]
        );

        $this->reopenEntityManager($entityManager);
        $this->persistenceManager->clearState();

        self::assertNotNull($caughtException, 'the duplicate jti must make the insert fail');
        self::assertSame(
            1,
            $unrevokedRecordsOfPriorFamily,
            'the supersession must roll back with the failed insert - never leave the editor without any live family'
        );
    }

    /**
     * Reads the live revocation state straight through DBAL: the family revocation is a
     * DQL UPDATE that bypasses the identity map, so records this test loaded earlier
     * would still report their stale in-memory flag.
     */
    protected function countUnrevokedRecordsOfFamily(string $familyId): int
    {
        return (int)$this->objectManager->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::RECORD_TABLE . ' WHERE familyid = ? AND revoked = 0',
            [$familyId]
        );
    }

    /**
     * Undoes the close() of the shared EntityManager singleton, which has no public
     * reopen: leaving it closed would break every later test and the test case teardown.
     */
    private function reopenEntityManager(EntityManagerInterface $entityManager): void
    {
        $property = new ReflectionProperty(EntityManager::class, 'closed');
        $property->setAccessible(true);
        $property->setValue($entityManager, false);
    }

    /**
     * Swaps an injected dependency of the refresh token service singleton for the duration
     * of one test and hands back the previous value, so the test can restore it.
     */
    protected function replaceServiceProperty(string $propertyName, object $replacement): object
    {
        $property = new ReflectionProperty(AgentRefreshTokenService::class, $propertyName);
        $property->setAccessible(true);
        $previousValue = $property->getValue($this->agentRefreshTokenService);
        $property->setValue($this->agentRefreshTokenService, $replacement);

        return $previousValue;
    }

    /**
     * Swaps the refresh token service the revocation aspect calls into, handing back the
     * previous value so the test can restore it.
     */
    protected function replaceAspectService(object $agentRefreshTokenService): object
    {
        $aspect = $this->objectManager->get(AgentRefreshTokenRevocationAspect::class);
        $property = new ReflectionProperty(AgentRefreshTokenRevocationAspect::class, 'agentRefreshTokenService');
        $property->setAccessible(true);
        $previousValue = $property->getValue($aspect);
        $property->setValue($aspect, $agentRefreshTokenService);

        return $previousValue;
    }

    protected function replaceTokenService(object $tokenService): object
    {
        return $this->replaceServiceProperty('agentTokenService', $tokenService);
    }

    protected function replaceRecordRepository(object $recordRepository): object
    {
        return $this->replaceServiceProperty('agentRefreshTokenRecordRepository', $recordRepository);
    }

    protected function replaceLogger(object $logger): object
    {
        return $this->replaceServiceProperty('logger', $logger);
    }

    protected function expectRejection(?string $opaqueToken, string $expectedReason): void
    {
        try {
            $this->agentRefreshTokenService->redeemRefreshToken($opaqueToken);
            self::fail('Expected an AgentRefreshTokenRejectedException with reason ' . $expectedReason);
        } catch (AgentRefreshTokenRejectedException $e) {
            self::assertSame($expectedReason, $e->getReason());
        }
    }

    /**
     * Creates a token record directly (bypassing the issue path) so the expiry dates are
     * fully controlled by the test.
     *
     * @param array{refreshExpiresAt?: DateTimeImmutable, familyExpiresAt?: DateTimeImmutable, familyId?: string, consumerMarker?: string, jti?: string, revoked?: bool} $overrides
     * @return array{0: string, 1: AgentRefreshTokenRecord} The opaque token and its record
     */
    protected function createRecord(Account $account, array $overrides = []): array
    {
        $opaqueToken = bin2hex(random_bytes(32));
        $record = new AgentRefreshTokenRecord(
            hash('sha256', $opaqueToken),
            $overrides['jti'] ?? Algorithms::generateUUID(),
            $this->persistenceManager->getIdentifierByObject($account),
            $overrides['familyId'] ?? Algorithms::generateUUID(),
            $overrides['refreshExpiresAt'] ?? new DateTimeImmutable('+30 days'),
            $overrides['familyExpiresAt'] ?? new DateTimeImmutable('+90 days'),
            $overrides['consumerMarker'] ?? AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT
        );
        if ($overrides['revoked'] ?? false) {
            $record->revoke();
        }
        $this->recordRepository->add($record);
        $this->persistenceManager->persistAll();

        return [$opaqueToken, $record];
    }

    protected function createBackendAccount(): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('refresh-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $this->accountRepository->add($account);
        $this->persistenceManager->persistAll();

        return $account;
    }

    protected function createBackendAccountWithUser(): Account
    {
        $account = $this->createBackendAccount();

        $user = new User();
        $user->setName(new PersonName('', 'Refresh', '', 'Tester'));
        $this->partyRepository->add($user);
        $this->partyService->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }
}

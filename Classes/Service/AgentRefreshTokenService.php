<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Flow\Utility\Algorithms;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;
use NEOSidekick\AiAssistant\Domain\Repository\AgentRefreshTokenRecordRepository;
use NEOSidekick\AiAssistant\Exception\AgentRefreshTokenRejectedException;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Issues, rotates and revokes the opaque refresh credentials backing the server-to-server
 * renewal path for new-generation agent JWTs.
 *
 * Lifetime model (pinned decision): a 30-day sliding window - every successful renewal
 * extends validity by 30 days - under a 90-day absolute family cap fixed at family
 * creation, so even an always-active editor re-consents quarterly.
 *
 * The refresh grant derives identity from the token record only (the pinned Account
 * persistence UUID), never from caller input, and re-checks that the pinned account
 * still exists and is active - the same active-account semantics the JwtProvider
 * applies per request. Presenting a token that was already ROTATED away is treated as a
 * theft signal: the entire family is revoked. A token revoked without rotation (explicit
 * logout, or supersession by a fresh consent) is merely rejected.
 *
 * @Flow\Scope("singleton")
 */
class AgentRefreshTokenService
{
    /**
     * Sliding validity window of one refresh token in seconds (30 days).
     */
    public const REFRESH_TOKEN_SLIDING_LIFETIME = 30 * 86400;

    /**
     * Absolute cap of a whole token family in seconds (90 days), fixed at family creation.
     */
    public const REFRESH_FAMILY_ABSOLUTE_LIFETIME = 90 * 86400;

    /**
     * How long a refresh record is kept after its family expired, in seconds. An access
     * token lives ACCESS_TOKEN_LIFETIME (3600 s) from its mint and is NOT clipped to the
     * family expiry, and the JwtProvider grants a further 60 s of verification leeway - so
     * a record may still be the `jti` anchor of a live token for that long after its family
     * died. The bound is doubled to stay trivially safe under clock skew between the
     * minting and the verifying process.
     */
    public const REFRESH_RECORD_RETENTION_SECONDS = 2 * AgentTokenService::ACCESS_TOKEN_LIFETIME;

    /**
     * The bound an opaque refresh token must satisfy to be looked up at all - shared with
     * the public revoke endpoint, so both agree on what "malformed" means.
     */
    public const REFRESH_TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @Flow\Inject
     * @var AgentRefreshTokenRecordRepository
     */
    protected AgentRefreshTokenRecordRepository $agentRefreshTokenRecordRepository;

    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected AgentTokenService $agentTokenService;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected PersistenceManagerInterface $persistenceManager;

    /**
     * Carries the one explicit transaction this service needs: the refresh token rotation.
     *
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Cheap probe of the refresh token storage: answers whether the table backing the
     * refresh credentials actually exists and is queryable. An installation that never
     * ran `./flow doctrine:migrate` after the update must keep the legacy path instead of
     * minting a new-generation token whose refresh family can never be persisted - which
     * would leave the backend side holding a refresh credential this install cannot honour.
     */
    public function isStorageReady(): bool
    {
        try {
            $this->agentRefreshTokenRecordRepository->countAll();

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'NEOSidekick agent refresh: the refresh token storage is not available (has doctrine:migrate been run?): ' . $e->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return false;
        }
    }

    /**
     * Issues the opaque refresh token accompanying a consent-time (authorization flow)
     * JWT mint: creates a fresh token family for the given account and revokes every
     * prior family of that account - one credential per row; a fresh consent supersedes.
     *
     * Only the returned opaque token ever leaves this service; the record stores its hash.
     *
     * @param string $accountUuid Persistence UUID of the Neos Account (the `account_id` claim value)
     * @param string $jti The `jti` claim of the JWT minted alongside
     * @param string $consumerMarker Which consumer the new family belongs to; every WP0 call site leaves this at 'chat'
     * @return string The opaque refresh token (64 hex chars)
     */
    public function issueRefreshTokenForNewFamily(string $accountUuid, string $jti, string $consumerMarker = AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT): string
    {
        $opaqueToken = $this->generateOpaqueRefreshToken();
        $this->commitRefreshTokenForNewFamily($opaqueToken, $accountUuid, $jti, $consumerMarker);

        return $opaqueToken;
    }

    /**
     * Generates a fresh opaque refresh token WITHOUT persisting anything. The two-phase
     * split exists for the authorization callback: the opaque token must ride the
     * callback POST to Laravel, but the new family may only be persisted - and the prior
     * families revoked - once that callback SUCCEEDED. Committing first would let a
     * benign 422 or a timeout revoke the credential Laravel still holds, turning its
     * next renewal into a replay-shaped 401.
     */
    public function generateOpaqueRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Second phase of {@see generateOpaqueRefreshToken()}: revokes every prior family of
     * the account and persists the record of the new family for the given opaque token.
     * Only ever call this after the callback carrying the token was answered with
     * success - a failed callback must leave the prior families untouched.
     *
     * The supersession is scoped to the consumer marker: a fresh consent only revokes the
     * prior families of the SAME consumer, never another consumer's credential chain.
     */
    public function commitRefreshTokenForNewFamily(string $opaqueToken, string $accountUuid, string $jti, string $consumerMarker = AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT): void
    {
        $now = new DateTimeImmutable();

        /*
         * A closed EntityManager makes Flow's persistAll() silently no-op, while the DQL
         * revoke of the prior families does not: without this guard the supersession would
         * commit alone, with no successor record and no exception - every prior family dead
         * and the platform holding a refresh token that was never persisted. Refuse before
         * the transaction starts, with an infrastructure error rather than a rejection.
         */
        if (!$this->entityManager->isOpen()) {
            throw new RuntimeException(
                'NEOSidekick agent refresh: the EntityManager is closed, so the new refresh token family cannot be committed.',
                1756512001
            );
        }

        /*
         * The supersession is a DQL UPDATE that commits on its own, so without a
         * transaction spanning both a failing insert would leave every prior family dead
         * with no successor - while the platform already holds the new refresh token. The
         * editor would lose the working sessions AND the new one.
         */
        $this->entityManager->beginTransaction();
        try {
            $revokedRecords = $this->revokeAllRecordsForAccountUuid($accountUuid, $consumerMarker);

            $record = new AgentRefreshTokenRecord(
                hash('sha256', $opaqueToken),
                $jti,
                $accountUuid,
                Algorithms::generateUUID(),
                $this->addSeconds($now, self::REFRESH_TOKEN_SLIDING_LIFETIME),
                $this->addSeconds($now, self::REFRESH_FAMILY_ABSOLUTE_LIFETIME),
                $consumerMarker
            );
            $this->agentRefreshTokenRecordRepository->add($record);
            $this->persistenceManager->persistAll();

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $e;
        }

        if ($revokedRecords > 0) {
            $this->logger->info(
                sprintf(
                    'NEOSidekick agent refresh: revoked %d prior refresh token record(s) of consumer %s for account %s superseded by a fresh authorization',
                    $revokedRecords,
                    $consumerMarker,
                    $accountUuid
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * The refresh grant: validates the presented opaque token against its record, mints a
     * new JWT for the pinned account and rotates the credential (successor record in the
     * same family, predecessor revoked).
     *
     * Rotation is guarded by a compare-and-swap: the predecessor is revoked via a
     * conditional UPDATE (`... WHERE revoked = 0`), so of two concurrent presentations of
     * the same valid token exactly one wins. The loser is answered with the REASON_SPENT
     * rejection - WITHOUT the replay family revocation, because a same-instant race can
     * be benign (two chat requests refreshing at once); only a replay of a token that was
     * already rotated away at check time keeps the theft-signal response.
     *
     * The new JWT is minted BEFORE that compare-and-swap: minting is side-effect-free,
     * while the CAS commits immediately - doing it the other way round burns the
     * credential on any transient minting failure, forcing the editor through a fresh
     * consent. Single use stays guaranteed by the CAS, which still runs before the
     * successor is handed out.
     *
     * Every failure raised as AgentRefreshTokenRejectedException is a POSITIVELY
     * identified rejection - the caller answers it with the 401 marker. Anything
     * inconclusive (infrastructure errors, signing failures) escapes as another
     * exception type and must surface as a marker-less 500.
     *
     * @return array{jwt: string, refresh_token: string, refresh_token_expires_at: int}
     * @throws AgentRefreshTokenRejectedException When the token is positively invalid
     * @throws AgentTokenException When minting the successor JWT fails for internal reasons
     * @throws RuntimeException When the persistence backend cannot complete the rotation
     */
    public function redeemRefreshToken(?string $opaqueToken): array
    {
        if (!is_string($opaqueToken) || preg_match(self::REFRESH_TOKEN_PATTERN, $opaqueToken) !== 1) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_MALFORMED,
                'The refresh token is missing or malformed.'
            );
        }

        $record = $this->agentRefreshTokenRecordRepository->findOneByRefreshTokenHash(hash('sha256', $opaqueToken));
        if ($record === null) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_UNKNOWN,
                'The refresh token is not known.'
            );
        }

        if ($record->isRevoked()) {
            if ($record->getRotatedAt() === null) {
                throw $this->rejection(
                    AgentRefreshTokenRejectedException::REASON_REVOKED,
                    'The refresh token has been revoked.'
                );
            }

            $revokedRecords = $this->agentRefreshTokenRecordRepository->revokeFamilyRecords($record->getFamilyId());
            $this->logger->error(
                sprintf(
                    'NEOSidekick agent refresh: SECURITY - a revoked refresh token was replayed; revoked the entire token family %s (%d additional record(s)) for account %s',
                    $record->getFamilyId(),
                    $revokedRecords,
                    $record->getAccountUuid()
                ),
                LogEnvironment::fromMethodName(__METHOD__)
            );
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_REPLAYED,
                'The refresh token has been revoked.',
                false
            );
        }

        $now = new DateTimeImmutable();
        if ($now >= $record->getRefreshExpiresAt()) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_WINDOW_EXPIRED,
                'The refresh token has expired.'
            );
        }
        if ($now >= $record->getFamilyExpiresAt()) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_FAMILY_EXPIRED,
                'The refresh token family has reached its absolute lifetime.'
            );
        }

        $account = null;
        $this->securityContext->withoutAuthorizationChecks(function () use ($record, &$account) {
            $account = $this->persistenceManager->getObjectByIdentifier($record->getAccountUuid(), Account::class);
        });
        if (!$account instanceof Account) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_ACCOUNT_NOT_FOUND,
                'The account bound to the refresh token no longer exists.'
            );
        }
        if (!$account->isActive()) {
            throw $this->rejection(
                AgentRefreshTokenRejectedException::REASON_ACCOUNT_INACTIVE,
                'The account bound to the refresh token is no longer active.'
            );
        }

        try {
            $tokenData = $this->agentTokenService->generateTokenDataForAccount($account);
        } catch (AgentTokenException $e) {
            if ($e->getStatusCode() === 401) {
                throw $this->rejection(
                    AgentRefreshTokenRejectedException::REASON_ACCOUNT_UNUSABLE,
                    'The account bound to the refresh token cannot be issued a token: ' . $e->getMessage()
                );
            }
            throw $e;
        }

        $successorOpaqueToken = bin2hex(random_bytes(32));
        $successorRefreshExpiresAt = min(
            $this->addSeconds($now, self::REFRESH_TOKEN_SLIDING_LIFETIME),
            $record->getFamilyExpiresAt()
        );

        /*
         * A closed EntityManager cannot flush the successor, but the predecessor's CAS
         * UPDATE would still commit on its own - burning the credential and leaving the
         * family with zero unrevoked records. Refuse before the transaction starts, and
         * refuse with an infrastructure error rather than a rejection: this is
         * inconclusive, and the caller must surface it as a marker-less 500.
         */
        if (!$this->entityManager->isOpen()) {
            throw new RuntimeException(
                'NEOSidekick agent refresh: the EntityManager is closed, so the refresh token rotation cannot be completed.',
                1756512000
            );
        }

        /*
         * The predecessor's CAS revocation is a DQL UPDATE that commits on its own, while
         * the successor record only reaches the database at persistAll() - without a
         * transaction spanning both, a live family is briefly COMMITTED with zero
         * unrevoked records, and the JwtProvider's family-liveness check would reject
         * every access token of that family inside the window.
         */
        $this->entityManager->beginTransaction();
        try {
            $spentRows = $this->agentRefreshTokenRecordRepository->markSpentIfNotRevoked($record, $now);
            if ($spentRows === 0) {
                throw $this->rejection(
                    AgentRefreshTokenRejectedException::REASON_SPENT,
                    'The refresh token was already spent by a concurrent renewal.'
                );
            }
            $record->markRotated($now);

            $successorRecord = new AgentRefreshTokenRecord(
                hash('sha256', $successorOpaqueToken),
                $tokenData['jti'],
                $record->getAccountUuid(),
                $record->getFamilyId(),
                $successorRefreshExpiresAt,
                $record->getFamilyExpiresAt(),
                $record->getConsumerMarker()
            );
            $this->agentRefreshTokenRecordRepository->add($successorRecord);
            $this->agentRefreshTokenRecordRepository->update($record);

            $this->persistenceManager->persistAll();

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $e;
        }

        $this->pruneExpiredRecordsOfAccount($record->getAccountUuid());

        return [
            'jwt' => $tokenData['jwt'],
            'refresh_token' => $successorOpaqueToken,
            'refresh_token_expires_at' => $successorRefreshExpiresAt->getTimestamp(),
        ];
    }

    /**
     * Revokes every refresh token record (all families) of the given accounts - the
     * explicit-logout semantics: called by {@see AgentRefreshTokenRevocationAspect} with the
     * account UUIDs it captured from the security context's authenticated tokens BEFORE
     * Flow de-authenticated them (AuthenticationProviderManager::logout() sets every
     * token to NO_CREDENTIALS_GIVEN before any post-logout hook runs, at which point the
     * tokens no longer surrender their accounts).
     *
     * Scope: an explicit Neos logout ends the Neos chat session, so it revokes the
     * CHAT-marked families only (AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT). An
     * external consumer's credential chain is not bound to the editor's backend session
     * and must survive the logout.
     *
     * Never throws: a broken revocation (e.g. the migration not yet applied) must not
     * break every backend logout - the failure is logged at error level instead.
     *
     * @param array<string> $accountUuids Persistence UUIDs of the logged-out accounts
     */
    public function revokeRefreshTokensOfAccounts(array $accountUuids): void
    {
        $this->revokeRecordsOfAccounts(
            $accountUuids,
            AgentRefreshTokenRecord::CONSUMER_MARKER_CHAT,
            'NEOSidekick agent refresh: revoked %d chat refresh token record(s) on explicit logout of account(s) %s',
            'NEOSidekick agent refresh: revoking refresh tokens on logout failed: '
        );
    }

    /**
     * Revokes every refresh token record of the given accounts across ALL consumer
     * markers - the password-change semantics: a new password means the old credentials
     * may have leaked, so everything minted under the old trust dies, including external
     * consumers' credential chains that a logout deliberately spares.
     *
     * Never throws: a broken revocation must not break the password change itself - the
     * failure is logged at error level instead. The worst case of an over-revoke is one
     * re-consent, which prior consent makes a single silent authorization card.
     *
     * @param array<string> $accountUuids Persistence UUIDs of the accounts of the user whose password changes
     */
    public function revokeAllRefreshTokensOfAccounts(array $accountUuids): void
    {
        $this->revokeRecordsOfAccounts(
            $accountUuids,
            null,
            'NEOSidekick agent refresh: revoked %d refresh token record(s) of all consumers on password change of account(s) %s',
            'NEOSidekick agent refresh: revoking refresh tokens on password change failed: '
        );
    }

    /**
     * Revokes the ENTIRE rotation family named by a presented opaque refresh token - the
     * inbound revoke grant: possession of a family's refresh secret is the right to end
     * that family, exactly as it is the right to renew it. No other credential is
     * involved, so no shared secret has to travel the fleet.
     *
     * Family-wide, not record-wide, and deliberately tolerant of a stale token: a spent
     * or rotated-away token still NAMES its family, so a caller that lost a rotation race
     * (and therefore holds the predecessor) still revokes the successor it never saw.
     *
     * Silent for anything it cannot act on - an unknown token, or an installation whose
     * refresh storage never got migrated - because the endpoint in front of this must
     * stay a non-oracle: known, unknown, already-revoked and expired tokens all leave
     * through the same answer. Only an EFFECTIVE revoke (at least one record newly
     * revoked) writes a log line.
     *
     * @param string $rawToken The opaque refresh token as presented by the caller
     */
    public function revokeFamilyOfRefreshToken(string $rawToken): void
    {
        if (!$this->isStorageReady()) {
            return;
        }

        $record = $this->agentRefreshTokenRecordRepository->findOneByRefreshTokenHash(hash('sha256', $rawToken));
        if ($record === null) {
            return;
        }

        $revokedRecords = $this->agentRefreshTokenRecordRepository->revokeFamilyRecords($record->getFamilyId());
        if ($revokedRecords === 0) {
            return;
        }

        $this->logger->info(
            sprintf(
                'NEOSidekick agent refresh: revoked the refresh token family %s of consumer %s (%d record(s)) on an inbound revoke request',
                $record->getFamilyId(),
                $record->getConsumerMarker(),
                $revokedRecords
            ),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }

    /**
     * The shared body of the two public revocation events: normalises the account UUIDs,
     * revokes their records, persists once and logs the outcome - never throwing, so a
     * broken revocation cannot break the logout or password change that triggered it.
     *
     * The consumer marker is a REQUIRED argument with no default and is never derived
     * here: the scope difference between the two events (chat-only vs. every consumer) is
     * the security-relevant decision, and it must stay visible at each call site so that
     * widening one event can never silently widen the other.
     *
     * @param array<string> $accountUuids Persistence UUIDs of the affected accounts
     * @param string|null $consumerMarker The consumer scope of this event; null sweeps all markers
     * @param string $successLogFormat sprintf format taking the record count and the account UUID list
     * @param string $failureLogPrefix Prefixed to the exception message of a failed revocation
     */
    private function revokeRecordsOfAccounts(
        array $accountUuids,
        ?string $consumerMarker,
        string $successLogFormat,
        string $failureLogPrefix
    ): void {
        try {
            $accountUuids = array_values(array_unique(array_filter($accountUuids, static function ($accountUuid) {
                return is_string($accountUuid) && $accountUuid !== '';
            })));

            $revokedRecords = 0;
            foreach ($accountUuids as $accountUuid) {
                $revokedRecords += $this->revokeAllRecordsForAccountUuid($accountUuid, $consumerMarker);
            }

            if ($revokedRecords > 0) {
                $this->logger->info(
                    sprintf($successLogFormat, $revokedRecords, implode(', ', $accountUuids)),
                    LogEnvironment::fromMethodName(__METHOD__)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                $failureLogPrefix . $e->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * Revokes every not-yet-revoked record of the given account across all families,
     * optionally restricted to a single consumer marker. See
     * {@see AgentRefreshTokenRecordRepository::revokeAccountRecords()} for why this is one
     * statement rather than a loop over a loaded result set.
     *
     * @param string|null $consumerMarker When given, records of any other consumer are left alone
     * @return int Number of records newly revoked
     */
    protected function revokeAllRecordsForAccountUuid(string $accountUuid, ?string $consumerMarker = null): int
    {
        return $this->agentRefreshTokenRecordRepository->revokeAccountRecords($accountUuid, $consumerMarker);
    }

    /**
     * Deletes the account's refresh records whose family expiry is older than the
     * retention cutoff - the only thing that ever removes rows, running on the rotation
     * path right after its transaction committed: about hourly per active credential, so
     * it needs neither an operator nor a scheduler. Accounts that never rotate again keep
     * their rows, an accepted bound.
     *
     * Never fails the renewal it rides on: a broken prune is a housekeeping problem, while
     * the caller already holds a successfully rotated credential.
     */
    private function pruneExpiredRecordsOfAccount(string $accountUuid): void
    {
        try {
            $cutoff = $this->addSeconds(new DateTimeImmutable(), -self::REFRESH_RECORD_RETENTION_SECONDS);
            $deletedRecords = $this->agentRefreshTokenRecordRepository->deleteExpiredRecordsOfAccount($accountUuid, $cutoff);
            if ($deletedRecords > 0) {
                $this->logger->info(
                    sprintf(
                        'NEOSidekick agent refresh: pruned %d expired refresh token record(s) of account %s',
                        $deletedRecords,
                        $accountUuid
                    ),
                    LogEnvironment::fromMethodName(__METHOD__)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'NEOSidekick agent refresh: pruning expired refresh token records failed: ' . $e->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }
    }

    /**
     * Adds exact epoch seconds. PHP's relative `modify('+N seconds')` applies wall-clock
     * arithmetic, so it silently drifts by an hour across a DST boundary - the token
     * lifetimes must be exact epoch offsets instead.
     */
    private function addSeconds(DateTimeImmutable $dateTime, int $seconds): DateTimeImmutable
    {
        return $dateTime->setTimestamp($dateTime->getTimestamp() + $seconds);
    }

    /**
     * Builds the rejection exception and writes the named log event for it; every
     * positively-identified rejection reason produces exactly one warning line (the
     * replay case logs its own security event at error level before calling this).
     */
    private function rejection(string $reason, string $message, bool $log = true): AgentRefreshTokenRejectedException
    {
        if ($log) {
            $this->logger->warning(
                sprintf('NEOSidekick agent refresh token rejected (%s): %s', $reason, $message),
                LogEnvironment::fromMethodName(__METHOD__)
            );
        }

        return new AgentRefreshTokenRejectedException($reason, $message);
    }
}

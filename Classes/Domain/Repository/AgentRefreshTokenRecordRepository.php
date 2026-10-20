<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Domain\Repository;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Flow\Persistence\Repository;
use NEOSidekick\AiAssistant\Domain\Model\AgentRefreshTokenRecord;

/**
 * @Flow\Scope("singleton")
 * @method QueryResultInterface<AgentRefreshTokenRecord> findByFamilyId(string $familyId)
 */
class AgentRefreshTokenRecordRepository extends Repository
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * Looks up the record by the sha256 hash of a presented opaque token. Hashing before
     * lookup is the constant-time defense - the raw token is never compared or stored.
     */
    public function findOneByRefreshTokenHash(string $refreshTokenHash): ?AgentRefreshTokenRecord
    {
        $query = $this->createQuery();
        /** @var AgentRefreshTokenRecord|null $record */
        $record = $query->matching($query->equals('refreshTokenHash', $refreshTokenHash))
            ->execute()
            ->getFirst();

        return $record;
    }

    /**
     * Looks up the record carrying the given `jti` claim - the liveness anchor of a
     * new-generation access token: every production mint writes exactly one record with
     * the JWT's `jti`, and the column is UNIQUE, so the lookup is deterministic.
     */
    public function findOneByJti(string $jti): ?AgentRefreshTokenRecord
    {
        $query = $this->createQuery();
        /** @var AgentRefreshTokenRecord|null $record */
        $record = $query->matching($query->equals('jti', $jti))
            ->execute()
            ->getFirst();

        return $record;
    }

    /**
     * Whether the given rotation family still holds at least one unrevoked record - the
     * liveness predicate for every access token of that family. Rotation always leaves
     * the successor unrevoked, while logout, password change, re-consent supersession
     * and the replay response revoke the family as a whole.
     */
    public function familyHasUnrevokedRecord(string $familyId): bool
    {
        $query = $this->createQuery();

        return $query->matching(
            $query->logicalAnd(
                $query->equals('familyId', $familyId),
                $query->equals('revoked', false)
            )
        )->count() > 0;
    }

    /**
     * Revokes every not-yet-revoked record of a rotation family in ONE statement, directly
     * in the database - the family-wide kill switch behind the inbound revoke endpoint,
     * bypassing the identity map exactly like {@see markSpentIfNotRevoked()}.
     *
     * Against a rotation running concurrently this relies on that rotation being one
     * transaction and on REPEATABLE READ next-key locking over the familyId index; under
     * READ COMMITTED a successor committed behind the scan could escape the kill (accepted).
     *
     * @return int Number of records newly revoked
     */
    public function revokeFamilyRecords(string $familyId): int
    {
        $query = $this->entityManager->createQuery(
            'UPDATE ' . AgentRefreshTokenRecord::class . ' r'
            . ' SET r.revoked = true'
            . ' WHERE r.familyId = :familyId AND r.revoked = false'
        );

        return (int)$query->execute([
            'familyId' => $familyId,
        ]);
    }

    /**
     * Revokes every not-yet-revoked record of one account in ONE statement, optionally
     * restricted to a single consumer marker - the bulk form behind the logout, the
     * password change and the re-consent supersession, bypassing the identity map exactly
     * like {@see revokeFamilyRecords()}.
     *
     * The marker fragment and its parameter are added together and only when a marker is
     * given: Doctrine throws on a parameter the DQL does not mention.
     *
     * @param string|null $consumerMarker When given, records of any other consumer are left alone
     * @return int Number of records newly revoked
     */
    public function revokeAccountRecords(string $accountUuid, ?string $consumerMarker = null): int
    {
        $dql = 'UPDATE ' . AgentRefreshTokenRecord::class . ' r'
            . ' SET r.revoked = true'
            . ' WHERE r.accountUuid = :accountUuid AND r.revoked = false';
        $parameters = [
            'accountUuid' => $accountUuid,
        ];
        if ($consumerMarker !== null) {
            $dql .= ' AND r.consumerMarker = :consumerMarker';
            $parameters['consumerMarker'] = $consumerMarker;
        }

        return (int)$this->entityManager->createQuery($dql)->execute($parameters);
    }

    /**
     * Deletes the records of one account whose family expiry lies before the given cutoff -
     * the retention prune, scoped to a single account so it rides the accountuuid index.
     *
     * The cutoff must stay far enough in the past that no access token minted from a
     * deleted record can still verify: the JwtProvider resolves an access token through
     * findOneByJti(), and a pruned record turns a live token into a 401.
     *
     * @return int Number of records deleted
     */
    public function deleteExpiredRecordsOfAccount(string $accountUuid, DateTimeInterface $cutoff): int
    {
        $query = $this->entityManager->createQuery(
            'DELETE ' . AgentRefreshTokenRecord::class . ' r'
            . ' WHERE r.accountUuid = :accountUuid AND r.familyExpiresAt < :cutoff'
        );

        return (int)$query->execute([
            'accountUuid' => $accountUuid,
            'cutoff' => $cutoff,
        ]);
    }

    /**
     * Compare-and-swap revocation of a record being spent through rotation: revokes it
     * (and stamps rotatedAt) only if it is not revoked yet, directly in the database,
     * bypassing the identity map - so of two concurrent redemptions that both read the
     * record as unrevoked, exactly one gets an affected row. The caller must treat 0
     * affected rows as "concurrently spent" and must not mint a successor.
     *
     * @return int Number of affected rows (0 or 1)
     */
    public function markSpentIfNotRevoked(AgentRefreshTokenRecord $record, DateTimeInterface $rotatedAt): int
    {
        $query = $this->entityManager->createQuery(
            'UPDATE ' . AgentRefreshTokenRecord::class . ' r'
            . ' SET r.revoked = true, r.rotatedAt = :rotatedAt'
            . ' WHERE r = :record AND r.revoked = false'
        );

        return (int)$query->execute([
            'record' => $record,
            'rotatedAt' => $rotatedAt,
        ]);
    }
}

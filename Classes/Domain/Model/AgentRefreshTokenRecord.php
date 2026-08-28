<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Domain\Model;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * One opaque, single-use, rotating refresh credential (the plugin's first Doctrine
 * entity - DB storage is pinned deliberately: a Flow-cache "simplification" would
 * reintroduce the wiped-store failure mode via cache flushes).
 *
 * Only the sha256 hash of the opaque token is ever stored; the hash lookup doubles
 * as the constant-time comparison. Identity is pinned to the Neos Account's
 * persistence UUID: the refresh grant derives the renewed JWT's identity from this
 * record only, never from caller input, so username reuse can never revive an old
 * chain. Rotation carries the familyId across successor records; familyExpiresAt is
 * fixed at family creation (the 90-day absolute cap on the 30-day sliding window).
 *
 * @Flow\Entity
 * @ORM\Table(
 *     uniqueConstraints={
 *         @ORM\UniqueConstraint(name="uniq_nsk_agentrefresh_jti", columns={"jti"})
 *     },
 *     indexes={
 *         @ORM\Index(name="idx_nsk_agentrefresh_familyid", columns={"familyid"}),
 *         @ORM\Index(name="idx_nsk_agentrefresh_accountuuid", columns={"accountuuid"})
 *     }
 * )
 */
class AgentRefreshTokenRecord
{
    /**
     * The consumer marker of the Neos chat frame - and of every callback that carried
     * no marker.
     */
    public const CONSUMER_MARKER_CHAT = 'chat';

    /**
     * Lowercase hex sha256 of the opaque refresh token. The token itself is never stored.
     *
     * @var string
     * @ORM\Column(length=64, unique=true)
     */
    protected string $refreshTokenHash;

    /**
     * The `jti` claim of the JWT this refresh token was issued alongside. UNIQUE: every
     * mint generates a fresh UUID, and the JwtProvider resolves an access token to this
     * record - and through it to the family whose liveness decides the token's validity -
     * by this column alone.
     *
     * That resolution is also a retention constraint: a record must never be pruned while
     * any access token carrying its `jti` is still unexpired, or findOneByJti() returns
     * null and the provider 401s a token that is in fact live. The prune therefore keeps a
     * record until its FAMILY expiry is older than
     * AgentRefreshTokenService::REFRESH_RECORD_RETENTION_SECONDS (7200 s - twice the access
     * token lifetime, which is not clipped to the family expiry); it is enforced in
     * AgentRefreshTokenService::pruneExpiredRecordsOfAccount(), run on the rotation path.
     *
     * @var string
     * @ORM\Column(length=40)
     */
    protected string $jti;

    /**
     * Persistence UUID of the Neos Account this credential is pinned to.
     *
     * @var string
     * @ORM\Column(length=40)
     */
    protected string $accountUuid;

    /**
     * UUID shared by all records of one rotation chain.
     *
     * @var string
     * @ORM\Column(length=40)
     */
    protected string $familyId;

    /**
     * End of the sliding validity window of this record.
     *
     * @var DateTimeInterface
     */
    protected DateTimeInterface $refreshExpiresAt;

    /**
     * Absolute cap of the whole family, fixed at family creation and copied to every
     * successor. No rotation can slide past it.
     *
     * @var DateTimeInterface
     */
    protected DateTimeInterface $familyExpiresAt;

    /**
     * @var bool
     */
    protected bool $revoked = false;

    /**
     * Which consumer this credential chain belongs to: the literal 'chat' for the
     * Neos chat frame - and, permanently, for every callback that carried no marker -
     * or an external connection's opaque id.
     *
     * WP2 must constrain an external connection's `consumer_id` to a UUID, so that it can
     * never equal the literal 'chat' and be swept up by logout's string compare - a
     * compare pinned in AgentRefreshTokenService::revokeRefreshTokensOfAccounts(), not
     * here, and passed as null by the password-change revocation, which sweeps every
     * marker.
     *
     * @var string
     * @ORM\Column(length=64, options={"default"="chat"})
     */
    protected string $consumerMarker = self::CONSUMER_MARKER_CHAT;

    /**
     * @var DateTimeInterface
     */
    protected DateTimeInterface $creationDateTime;

    /**
     * Set when this record was spent through rotation (its revocation moment). A record
     * that is revoked without a rotatedAt was revoked by logout, re-consent supersession
     * or family revocation.
     *
     * @var DateTimeInterface|null
     * @ORM\Column(nullable=true)
     */
    protected ?DateTimeInterface $rotatedAt = null;

    public function __construct(
        string $refreshTokenHash,
        string $jti,
        string $accountUuid,
        string $familyId,
        DateTimeInterface $refreshExpiresAt,
        DateTimeInterface $familyExpiresAt,
        string $consumerMarker = self::CONSUMER_MARKER_CHAT
    ) {
        $this->refreshTokenHash = $refreshTokenHash;
        $this->jti = $jti;
        $this->accountUuid = $accountUuid;
        $this->familyId = $familyId;
        $this->refreshExpiresAt = $refreshExpiresAt;
        $this->familyExpiresAt = $familyExpiresAt;
        $this->consumerMarker = $consumerMarker;
        $this->creationDateTime = new DateTimeImmutable();
    }

    public function getRefreshTokenHash(): string
    {
        return $this->refreshTokenHash;
    }

    public function getJti(): string
    {
        return $this->jti;
    }

    public function getAccountUuid(): string
    {
        return $this->accountUuid;
    }

    public function getFamilyId(): string
    {
        return $this->familyId;
    }

    public function getRefreshExpiresAt(): DateTimeInterface
    {
        return $this->refreshExpiresAt;
    }

    public function getFamilyExpiresAt(): DateTimeInterface
    {
        return $this->familyExpiresAt;
    }

    public function getConsumerMarker(): string
    {
        return $this->consumerMarker;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function revoke(): void
    {
        $this->revoked = true;
    }

    public function getCreationDateTime(): DateTimeInterface
    {
        return $this->creationDateTime;
    }

    public function getRotatedAt(): ?DateTimeInterface
    {
        return $this->rotatedAt;
    }

    public function markRotated(DateTimeInterface $rotatedAt): void
    {
        $this->revoked = true;
        $this->rotatedAt = $rotatedAt;
    }
}

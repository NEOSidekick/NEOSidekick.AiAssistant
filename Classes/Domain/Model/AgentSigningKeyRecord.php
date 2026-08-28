<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Domain\Model;

use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * The single signing-key row of one Neos installation: the RSA keypair that signs
 * new-generation agent JWTs, the pending successor of a running rotation, and the
 * state of the last push of that key to the NEOSidekick backend. The unique `slot`
 * column limits the table to one row. The PEMs are stored in plaintext: never let
 * one reach an exception message, a flash message or a log.
 *
 * @Flow\Entity
 */
class AgentSigningKeyRecord
{
    /**
     * The only legal value of the `slot` column.
     */
    public const SLOT_INSTALL = 'install';

    /**
     * @var string
     * @ORM\Column(length=16, unique=true)
     */
    protected string $slot = self::SLOT_INSTALL;

    /**
     * The live signing key.
     *
     * @var string
     * @ORM\Column(type="text")
     */
    protected string $privateKeyPem;

    /**
     * The live verification key; the `kid` is derived from it on read, never stored.
     *
     * @var string
     * @ORM\Column(type="text")
     */
    protected string $publicKeyPem;

    /**
     * @var string|null
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $pendingPrivateKeyPem = null;

    /**
     * @var string|null
     * @ORM\Column(type="text", nullable=true)
     */
    protected ?string $pendingPublicKeyPem = null;

    /**
     * The kid of the pending successor, and the predicate of both compare-and-swap updates. Null means no rotation is in flight.
     *
     * @var string|null
     * @ORM\Column(length=64, nullable=true)
     */
    protected ?string $pendingKid = null;

    /**
     * Whether committing the pending pair must re-register the installation under the domain it was pushed with.
     *
     * @var bool
     */
    protected bool $pendingRelabel = false;

    /**
     * When the pending pair was last re-pushed.
     *
     * @var DateTimeInterface|null
     * @ORM\Column(nullable=true)
     */
    protected ?DateTimeInterface $pendingRetriedAt = null;

    /**
     * @var string|null
     * @ORM\Column(length=64, nullable=true)
     */
    protected ?string $pushKid = null;

    /**
     * @var string|null
     * @ORM\Column(nullable=true)
     */
    protected ?string $pushTarget = null;

    /**
     * @var string|null
     * @ORM\Column(length=64, nullable=true)
     */
    protected ?string $pushStatus = null;

    /**
     * @var string|null
     * @ORM\Column(length=32, nullable=true)
     */
    protected ?string $pushPluginVersion = null;

    /**
     * @var DateTimeInterface|null
     * @ORM\Column(nullable=true)
     */
    protected ?DateTimeInterface $pushedAt = null;

    /**
     * The lineage root the backend echoed on the last push; absent means unknown.
     *
     * @var string|null
     * @ORM\Column(length=64, nullable=true)
     */
    protected ?string $pushInstallRootKid = null;

    /**
     * Whether the last rotation re-enrolled the installation; only an explicit true counts as re-enrolled.
     *
     * @var bool|null
     * @ORM\Column(nullable=true)
     */
    protected ?bool $pushReenrolled = null;

    /**
     * @var DateTimeInterface|null
     * @ORM\Column(nullable=true)
     */
    protected ?DateTimeInterface $pushReenrolledAt = null;

    /**
     * The domain the backend has this installation's lineage registered under, echoed on the last
     * successful push; null when the backend echoed none. A null echo overwrites: the column is
     * what the backend last said, never a value carried forward.
     *
     * @var string|null
     * @ORM\Column(nullable=true)
     */
    protected ?string $pushRegisteredDomain = null;

    public function __construct(string $privateKeyPem, string $publicKeyPem)
    {
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = $publicKeyPem;
    }

    /**
     * For signing inside this plugin only - the private key must never leave the host.
     */
    public function getPrivateKeyPem(): string
    {
        return $this->privateKeyPem;
    }

    public function getPublicKeyPem(): string
    {
        return $this->publicKeyPem;
    }

    public function getPendingPrivateKeyPem(): ?string
    {
        return $this->pendingPrivateKeyPem;
    }

    public function getPendingPublicKeyPem(): ?string
    {
        return $this->pendingPublicKeyPem;
    }

    public function getPendingKid(): ?string
    {
        return $this->pendingKid;
    }

    public function isPendingRelabel(): bool
    {
        return $this->pendingRelabel;
    }

    public function getPendingRetriedAt(): ?DateTimeInterface
    {
        return $this->pendingRetriedAt;
    }

    public function getPushKid(): ?string
    {
        return $this->pushKid;
    }

    public function getPushTarget(): ?string
    {
        return $this->pushTarget;
    }

    public function getPushStatus(): ?string
    {
        return $this->pushStatus;
    }

    public function getPushPluginVersion(): ?string
    {
        return $this->pushPluginVersion;
    }

    public function getPushedAt(): ?DateTimeInterface
    {
        return $this->pushedAt;
    }

    public function getPushInstallRootKid(): ?string
    {
        return $this->pushInstallRootKid;
    }

    public function getPushReenrolled(): ?bool
    {
        return $this->pushReenrolled;
    }

    public function getPushReenrolledAt(): ?DateTimeInterface
    {
        return $this->pushReenrolledAt;
    }

    public function getPushRegisteredDomain(): ?string
    {
        return $this->pushRegisteredDomain;
    }
}

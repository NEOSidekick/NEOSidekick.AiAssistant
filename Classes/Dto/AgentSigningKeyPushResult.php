<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto;

use Neos\Flow\Annotations as Flow;

/**
 * Outcome of a public-key push to the NEOSidekick backend.
 *
 * The push is never allowed to break the flow that triggered it, so failures are
 * carried in this value object instead of being thrown: callers decide whether to
 * print (CLI) or ignore (authorization flow) them.
 *
 * @Flow\Proxy(false)
 */
final class AgentSigningKeyPushResult
{
    /**
     * @param array<int, string>|null $hosts
     */
    private function __construct(
        public readonly bool $successful,
        public readonly ?string $status,
        public readonly ?string $keyId,
        public readonly ?string $fingerprint,
        public readonly ?string $errorMessage,
        public readonly ?string $rejectionReason,
        public readonly ?string $installRootKid,
        public readonly ?string $registeredDomain = null,
        public readonly ?array $hosts = null,
        public readonly ?string $hostsResult = null
    ) {
    }

    /**
     * @param string $status The status the backend recorded for the key, e.g. "confirmed"
     *                       (enrolled and trusted) or "revoked".
     * @param string|null $installRootKid The kid that identifies this installation's lineage in the
     *                                    backend; null when the backend did not echo one.
     * @param string|null $registeredDomain The domain label the backend has this lineage registered
     *                                      under; null when the backend did not echo one. A null is
     *                                      recorded as null - it is never carried forward.
     * @param array<int, string>|null $hosts The host set the backend has stored for this lineage
     *                                       (origins); null when the backend did not echo one - an
     *                                       older backend - which the panel renders as unknown.
     * @param string|null $hostsResult What the backend did with the pushed host set: `accepted`
     *                                 or a rejection reason (`invalid_signature`, `expired`,
     *                                 `base_host_unknown`, `empty`); null when not echoed.
     */
    public static function success(string $status, string $keyId, ?string $fingerprint, ?string $installRootKid = null, ?string $registeredDomain = null, ?array $hosts = null, ?string $hostsResult = null): self
    {
        return new self(true, $status, $keyId, $fingerprint, null, null, $installRootKid, $registeredDomain, $hosts, $hostsResult);
    }

    /**
     * @param string|null $rejectionReason The backend's machine-readable `reason` token from a
     *                                     rejection body (e.g. "chain_domain_mismatch"); null when
     *                                     the answer carried none or none arrived
     */
    public static function failure(string $errorMessage, ?string $keyId = null, ?string $rejectionReason = null): self
    {
        return new self(false, null, $keyId, null, $errorMessage, $rejectionReason, null, null);
    }

    /**
     * Whether the backend recorded the key as enrolled and trusted, i.e. immediately
     * usable for signing agent JWTs.
     */
    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}

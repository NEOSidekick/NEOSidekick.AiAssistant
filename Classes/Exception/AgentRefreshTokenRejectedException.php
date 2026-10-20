<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Exception;

use Neos\Flow\Annotations as Flow;

/**
 * Thrown when a presented refresh token is positively identified as invalid - and only
 * then. The refresh endpoint answers it with the 401 + NEOS_REFRESH_TOKEN_REJECTED
 * marker, which the Laravel side treats as "invalidate the row's refresh state and fall
 * back to the browser-mediated flow". Internal errors must never surface as this
 * exception: everything inconclusive stays a 500 without the marker.
 *
 * REASON_MALFORMED is the one reason that does NOT carry the marker: no syntactically
 * well-formed token was even looked up, so the request never produced a verdict about a
 * credential. It is answered with a marker-less 400, which the Laravel side treats as
 * inconclusive and leaves the stored refresh token untouched.
 *
 * @Flow\Proxy(false)
 */
class AgentRefreshTokenRejectedException extends \Exception
{
    public const REASON_MALFORMED = 'malformed_refresh_token';
    public const REASON_UNKNOWN = 'unknown_refresh_token';
    public const REASON_REPLAYED = 'revoked_refresh_token_replayed';

    /**
     * A token that was revoked WITHOUT ever being rotated away - an explicit backend
     * logout, or supersession by a fresh consent. Not a theft signal: no family is torched
     * and nothing is logged as a security event; the credential is simply gone.
     */
    public const REASON_REVOKED = 'revoked_refresh_token';
    public const REASON_SPENT = 'refresh_token_spent';
    public const REASON_WINDOW_EXPIRED = 'refresh_window_expired';
    public const REASON_FAMILY_EXPIRED = 'refresh_family_expired';
    public const REASON_ACCOUNT_NOT_FOUND = 'account_not_found';
    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';
    public const REASON_ACCOUNT_UNUSABLE = 'account_unusable';

    private string $reason;

    public function __construct(string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    /**
     * One of the REASON_* constants - the named, logged rejection reason.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}

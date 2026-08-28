<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Log\Utility\LogEnvironment;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * The rejection budget of the two public agent endpoints (refresh and revoke), whose only
 * credential is an opaque 64-hex secret nobody has to be logged in to present.
 *
 * The budget is per client address AND per endpoint: the endpoint name is part of the
 * cache entry identifier, so a burst of refresh rejections during a mass invalidation can
 * never spend the budget a revoke of the same address needs, and vice versa.
 *
 * Counts REJECTIONS only, never successes: the fleet's renewers are legitimate at any
 * rate, while a guessing client produces nothing but 4xx. Budget and window are class
 * constants - no setting - until an operator actually asks for a knob.
 *
 * The throttle fails OPEN: every cache read and write is guarded, and a cache whose
 * COUNTER cannot be read or written (unwritable or full Data/Temporary, a directory that
 * cannot be created, a failing garbage collection) disables the budget for that call -
 * logged as a warning - instead of turning an unavailable cache into a 500 on an endpoint
 * whose status codes are a cross-repo contract. A failure while writing the
 * once-per-window log marker is the exception: it only skips the log line, because the
 * counter was already read over budget and the 429 verdict stands.
 *
 * The window is a tumbling one: the current window number is part of the cache entry
 * identifier, so a window ends by the identifier changing rather than by an entry being
 * extended - a persistent attacker can therefore never keep their own block alive past
 * the window they earned it in.
 *
 * Client address = Flow's CLIENT_IP request attribute, which the TrustedProxiesMiddleware
 * resolves from the configured trusted proxies; REMOTE_ADDR is never read directly. That a
 * spoofed X-Forwarded-For cannot buy an attacker a fresh budget therefore holds only while
 * `Neos.Flow.http.trustedProxies.proxies` names the real proxies: under the wildcard `'*'`
 * the middleware believes any forwarded-for header, so the key becomes caller-written.
 *
 * The counter is a non-atomic read-modify-write (get, increment, set), so concurrent
 * requests can overwrite each other's increment: the budget is an approximate bound, not
 * an exact one.
 *
 * @Flow\Scope("singleton")
 */
class AgentEndpointThrottle
{
    /**
     * Rejections one client address may produce per window before it is answered with 429.
     */
    public const MAX_REJECTIONS_PER_WINDOW = 30;

    /**
     * Length of one budget window in seconds; also the Retry-After the caller is given.
     */
    public const WINDOW_SECONDS = 60;

    /**
     * Bucket name of the refresh endpoint.
     */
    public const ENDPOINT_REFRESH = 'refresh';

    /**
     * Bucket name of the revoke endpoint.
     */
    public const ENDPOINT_REVOKE = 'revoke';

    /**
     * @var VariableFrontend
     */
    protected $cache;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Whether the request's client address has spent its rejection budget for the given
     * endpoint in the current window. Logged once per window, address and endpoint, not
     * once per blocked hit.
     *
     * The two cache failures are NOT equivalent: a failure while READING the counter fails
     * open - the call answers false and logs - because nothing is known about the budget.
     * A failure while writing the once-per-window log marker only costs the log line; the
     * counter was already read over budget, so the 429 verdict stands.
     */
    public function isLimited(ServerRequestInterface $request, string $endpoint): bool
    {
        $clientAddress = $this->clientAddress($request);

        try {
            $rejections = $this->cache->get($this->entryIdentifier('rejections', $endpoint, $clientAddress));
        } catch (\Throwable $e) {
            $this->logCacheFailure($endpoint, $e);

            return false;
        }

        if (!is_int($rejections) || $rejections < self::MAX_REJECTIONS_PER_WINDOW) {
            return false;
        }

        $this->logLimitOnce($endpoint, $clientAddress);

        return true;
    }

    /**
     * Says once per window, address and endpoint that the address is now answered with 429.
     * Owns its cache failures: a marker that cannot be read or written may cost the log
     * line, never the verdict the caller already established.
     */
    private function logLimitOnce(string $endpoint, string $clientAddress): void
    {
        try {
            $loggedEntryIdentifier = $this->entryIdentifier('logged', $endpoint, $clientAddress);
            if ($this->cache->get($loggedEntryIdentifier) === true) {
                return;
            }
            $this->cache->set($loggedEntryIdentifier, true, [], self::WINDOW_SECONDS);
        } catch (\Throwable $e) {
            $this->logCacheFailure($endpoint, $e);

            return;
        }

        $this->logger->warning(
            sprintf(
                'NEOSidekick agent endpoint throttle: client %s exceeded %d rejections on the %s endpoint in %d seconds and is answered with 429 for the rest of the window',
                $clientAddress,
                self::MAX_REJECTIONS_PER_WINDOW,
                $endpoint,
                self::WINDOW_SECONDS
            ),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }

    /**
     * Books one 4xx outcome against the request's client address on the given endpoint. A
     * cache failure fails open: nothing is counted and the failure is logged.
     */
    public function countRejection(ServerRequestInterface $request, string $endpoint): void
    {
        try {
            $entryIdentifier = $this->entryIdentifier('rejections', $endpoint, $this->clientAddress($request));
            $rejections = $this->cache->get($entryIdentifier);
            $this->cache->set($entryIdentifier, (is_int($rejections) ? $rejections : 0) + 1, [], self::WINDOW_SECONDS);

            // The file backend reclaims expired entries only in collectGarbage(), which nothing
            // calls automatically - so a lottery does it here, bounding the live entries to
            // roughly one window's worth even under address churn.
            if (random_int(1, 1000) === 1) {
                $this->cache->collectGarbage();
            }
        } catch (\Throwable $e) {
            $this->logCacheFailure($endpoint, $e);
        }
    }

    /**
     * One warning per failed throttle call: an unavailable cache means the endpoint is
     * currently unthrottled, which an operator must see, while the call itself carries on.
     */
    private function logCacheFailure(string $endpoint, \Throwable $exception): void
    {
        $this->logger->warning(
            sprintf(
                'NEOSidekick agent endpoint throttle: the rejection budget of the %s endpoint could not be applied because its cache failed (%s); the call is served unthrottled',
                $endpoint,
                $exception->getMessage()
            ),
            LogEnvironment::fromMethodName(__METHOD__)
        );
    }

    /**
     * The cache entry identifier of one counter, scoped to the endpoint, the client address
     * and the current window number. The address is hashed because it is caller-influenced
     * data that must never reach a cache key or a file name unescaped.
     *
     * $endpoint must be one of the ENDPOINT_* constants: it is concatenated raw into the
     * cache identifier, which the file backend turns into a file name.
     */
    protected function entryIdentifier(string $counter, string $endpoint, string $clientAddress): string
    {
        return $counter . '_' . $endpoint . '_' . $this->currentWindow() . '_' . sha1($clientAddress);
    }

    /**
     * The number of the window the current moment falls into. Overridable so tests can
     * step the clock forward a window without waiting a minute.
     */
    protected function currentWindow(): int
    {
        return (int)floor(time() / self::WINDOW_SECONDS);
    }

    /**
     * The trusted client address, or a shared 'unknown' bucket when the middleware could
     * not resolve one (no REMOTE_ADDR at all) - never left unbucketed, which would hand
     * out an unlimited budget. Requests that land in that shared bucket consequently spend
     * one another's budget and can starve each other.
     */
    protected function clientAddress(ServerRequestInterface $request): string
    {
        $clientAddress = $request->getAttribute(ServerRequestAttributes::CLIENT_IP);

        return is_string($clientAddress) && $clientAddress !== '' ? $clientAddress : 'unknown';
    }
}

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Cache\Backend\BackendInterface;
use Neos\Cache\Backend\TransientMemoryBackend;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Http\ServerRequestAttributes;
use NEOSidekick\AiAssistant\Service\AgentEndpointThrottle;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The rejection budget of the public agent endpoints, exercised against a transient cache
 * backend: the budget is per client address AND per endpoint, spent by rejections only,
 * tumbles with the window rather than being extended by further hits, and fails open when
 * the cache is unavailable.
 */
class AgentEndpointThrottleTest extends TestCase
{
    private const CLIENT_A = '198.51.100.7';

    private const CLIENT_B = '203.0.113.9';

    /**
     * The throttle under test, with an overridable window number so a test can step the
     * clock forward a full window without waiting a minute.
     *
     * @var AgentEndpointThrottle
     */
    private $throttle;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->throttle = new class () extends AgentEndpointThrottle {
            public int $windowNumber = 1000;

            protected function currentWindow(): int
            {
                return $this->windowNumber;
            }
        };

        $backend = new TransientMemoryBackend(new EnvironmentConfiguration('Testing', sys_get_temp_dir()));
        $cache = new VariableFrontend('NEOSidekick_AiAssistant_RefreshThrottle', $backend);
        $backend->setCache($cache);
        $this->setThrottleProperty('cache', $cache);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->setThrottleProperty('logger', $this->logger);
    }

    /**
     * @test
     */
    public function theBudgetedNumberOfRejectionsPassesAndTheNextOneIsLimited(): void
    {
        $request = $this->requestFrom(self::CLIENT_A);

        for ($rejection = 0; $rejection < AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW; $rejection++) {
            self::assertFalse($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH), 'rejection ' . $rejection . ' is still within the budget');
            $this->throttle->countRejection($request, AgentEndpointThrottle::ENDPOINT_REFRESH);
        }

        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH), 'the request after the budget is spent must be limited');
    }

    /**
     * Successful renewals are legitimate at any rate - the fleet's renewers produce them
     * continuously - so only rejections may ever move the counter, and asking the throttle
     * about a request must never itself cost budget.
     *
     * @test
     */
    public function checkingTheLimitDoesNotSpendTheBudget(): void
    {
        $request = $this->requestFrom(self::CLIENT_A);

        for ($check = 0; $check <= AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW; $check++) {
            self::assertFalse(
                $this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH),
                'check ' . $check . ' must not have spent any budget'
            );
        }

        for ($rejection = 0; $rejection < AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW; $rejection++) {
            self::assertFalse(
                $this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH),
                'rejection ' . $rejection . ' is still within the full, unspent budget'
            );
            $this->throttle->countRejection($request, AgentEndpointThrottle::ENDPOINT_REFRESH);
        }

        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH), 'only the rejections spent the budget');
    }

    /**
     * The refresh and the revoke endpoint keep separate buckets: a mass invalidation that
     * makes every credential of an install produce a refresh 401 from one egress address
     * must never make that install's next revoke land as 429.
     *
     * @test
     */
    public function theTwoEndpointsHaveIndependentBudgetsForOneAddress(): void
    {
        $request = $this->requestFrom(self::CLIENT_A);

        $this->spendTheBudget($request, AgentEndpointThrottle::ENDPOINT_REFRESH);

        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH));
        self::assertFalse(
            $this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REVOKE),
            'one endpoint must never spend the budget of the other'
        );

        $this->spendTheBudget($request, AgentEndpointThrottle::ENDPOINT_REVOKE);

        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REVOKE));
    }

    /**
     * A cache that cannot be read or written must not turn into a 500 on an endpoint whose
     * status codes are a cross-repo contract: the throttle fails open, counts nothing and
     * says so once per failed call.
     *
     * @test
     */
    public function aFailingCacheDisablesTheBudgetAndIsLoggedPerCall(): void
    {
        $this->setThrottleProperty('cache', $this->cacheOverAFailingBackend());
        $this->logger->expects(self::exactly(2))->method('warning');

        $request = $this->requestFrom(self::CLIENT_A);

        self::assertFalse(
            $this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH),
            'an unavailable cache must never make the throttle answer 429'
        );
        $this->throttle->countRejection($request, AgentEndpointThrottle::ENDPOINT_REFRESH);
    }

    /**
     * A VariableFrontend over a backend whose reads and writes throw the way the file
     * backend does on an unwritable or full Data/Temporary.
     */
    private function cacheOverAFailingBackend(): VariableFrontend
    {
        $backend = $this->createMock(BackendInterface::class);
        $backend->method('get')->willThrowException(new CacheException('the cache backend is unavailable'));
        $backend->method('set')->willThrowException(new CacheException('the cache backend is unavailable'));

        return new VariableFrontend('NEOSidekick_AiAssistant_RefreshThrottle', $backend);
    }

    /**
     * The once-per-window log marker is bookkeeping, not the verdict: when the counter
     * reads over budget but the marker cannot be written, the caller must still be
     * answered with 429 - only the log line is lost. Failing closed on the marker write
     * would hand an attacker a way to buy themselves unthrottled requests out of a broken
     * cache write path.
     *
     * @test
     */
    public function aFailingLogMarkerWriteStillLimitsTheAddress(): void
    {
        $loggedWarnings = [];
        $this->logger->method('warning')->willReturnCallback(
            static function (string $message) use (&$loggedWarnings): void {
                $loggedWarnings[] = $message;
            }
        );

        $request = $this->requestFrom(self::CLIENT_A);
        $this->spendTheBudget($request);

        $this->setThrottleProperty('cache', $this->cacheOverABackendThatCannotWrite());

        self::assertTrue(
            $this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH),
            'a marker that cannot be written must never cost the 429 verdict'
        );

        self::assertCount(1, $loggedWarnings, 'only the cache failure is logged, the over-budget line is skipped');
        self::assertStringContainsString('its cache failed', $loggedWarnings[0]);
        self::assertStringNotContainsString('is answered with 429', $loggedWarnings[0]);
    }

    /**
     * A VariableFrontend over the same transient backend the test already filled, wrapped
     * so that every write throws the way the file backend does on an unwritable
     * Data/Temporary while the reads keep working.
     */
    private function cacheOverABackendThatCannotWrite(): VariableFrontend
    {
        $readableBackend = $this->readThroughBackend();

        $backend = $this->createMock(BackendInterface::class);
        $backend->method('get')->willReturnCallback(
            static function (string $entryIdentifier) use ($readableBackend) {
                return $readableBackend->get($entryIdentifier);
            }
        );
        $backend->method('set')->willThrowException(new CacheException('the cache backend is unavailable'));

        return new VariableFrontend('NEOSidekick_AiAssistant_RefreshThrottle', $backend);
    }

    /**
     * The backend the throttle is currently reading from, so a replacement frontend can
     * serve the entries the budget was spent into.
     */
    private function readThroughBackend(): BackendInterface
    {
        $property = new ReflectionProperty(VariableFrontend::class, 'backend');
        $property->setAccessible(true);

        $cacheProperty = new ReflectionProperty(AgentEndpointThrottle::class, 'cache');
        $cacheProperty->setAccessible(true);

        return $property->getValue($cacheProperty->getValue($this->throttle));
    }

    /**
     * @test
     */
    public function twoClientAddressesHaveIndependentBudgets(): void
    {
        $requestA = $this->requestFrom(self::CLIENT_A);
        $requestB = $this->requestFrom(self::CLIENT_B);

        $this->spendTheBudget($requestA);

        self::assertTrue($this->throttle->isLimited($requestA, AgentEndpointThrottle::ENDPOINT_REFRESH));
        self::assertFalse($this->throttle->isLimited($requestB, AgentEndpointThrottle::ENDPOINT_REFRESH), 'one address must never spend another address\' budget');
    }

    /**
     * The next window starts with a fresh budget, and further rejections inside the spent
     * one cannot extend the block: the window number is part of the entry identifier, so
     * the block ends with the window it was earned in, no matter how hard the caller keeps
     * hitting it.
     *
     * @test
     */
    public function theBudgetIsRestoredWhenTheWindowPasses(): void
    {
        $request = $this->requestFrom(self::CLIENT_A);
        $this->spendTheBudget($request);

        for ($rejection = 0; $rejection < 10; $rejection++) {
            self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH));
            $this->throttle->countRejection($request, AgentEndpointThrottle::ENDPOINT_REFRESH);
        }

        $this->throttle->windowNumber++;

        self::assertFalse($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH), 'the next window starts with a fresh budget');
    }

    /**
     * A throttled address is worth one log line per window, not one per blocked hit -
     * otherwise the throttle itself becomes the amplification the attacker wanted.
     *
     * @test
     */
    public function aThrottledAddressIsLoggedOncePerWindowAndNotPerHit(): void
    {
        $this->logger->expects(self::exactly(2))->method('warning');

        $request = $this->requestFrom(self::CLIENT_A);
        $this->spendTheBudget($request);

        for ($hit = 0; $hit < 5; $hit++) {
            self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH));
        }

        $this->throttle->windowNumber++;
        $this->spendTheBudget($request);
        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH));
    }

    /**
     * A request the trusted-proxy middleware could not resolve an address for still lands
     * in a bucket - a missing attribute must never mean an unlimited budget.
     *
     * @test
     */
    public function aRequestWithoutAResolvedClientAddressIsStillBucketed(): void
    {
        $request = new ServerRequest('POST', 'http://localhost/neosidekick/api/agentic/refresh-token');

        $this->spendTheBudget($request);

        self::assertTrue($this->throttle->isLimited($request, AgentEndpointThrottle::ENDPOINT_REFRESH));
    }

    private function spendTheBudget(ServerRequestInterface $request, string $endpoint = AgentEndpointThrottle::ENDPOINT_REFRESH): void
    {
        for ($rejection = 0; $rejection < AgentEndpointThrottle::MAX_REJECTIONS_PER_WINDOW; $rejection++) {
            $this->throttle->countRejection($request, $endpoint);
        }
    }

    private function requestFrom(string $clientAddress): ServerRequestInterface
    {
        return (new ServerRequest('POST', 'http://localhost/neosidekick/api/agentic/refresh-token'))
            ->withAttribute(ServerRequestAttributes::CLIENT_IP, $clientAddress);
    }

    private function setThrottleProperty(string $propertyName, object $value): void
    {
        $property = new ReflectionProperty(AgentEndpointThrottle::class, $propertyName);
        $property->setAccessible(true);
        $property->setValue($this->throttle, $value);
    }
}

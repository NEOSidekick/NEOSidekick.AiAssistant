<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Dispatcher;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Tests\FunctionalTestRequestHandler;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * A multi-site - one installation, one key, one Neos site per host - against the real host
 * collector and the real Domain records: what the push labels the installation with and which
 * hosts it vouches for from each host, and what the Configuration module's panel says on each
 * host, with NEOSidekick stubbed.
 *
 * `example.com` and `example2.com` are the two sites' Domain records (https, no port);
 * `www.example.com` is an alias Neos serves by suffix match without a record of its own.
 */
class AgentSigningKeyPushMultiSiteTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    protected array $siteHosts = ['example.com', 'example2.com'];

    protected static $testablePersistenceEnabled = true;

    /**
     * Off while the base class builds the sites (see {@see setUp}), on for the tests.
     */
    protected $testableSecurityEnabled = false;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $requestHistory = [];

    private ?AgentSigningKeyPushService $originalPushService = null;

    private ?Locale $localeToRestore = null;

    /**
     * The base class builds the sites and their Domain records, which the content repository's
     * node privileges would refuse to an unauthenticated test - so that runs with security off
     * (Flow then disables the authorization checks), and security is set up afterwards for the
     * module's administrator gate.
     */
    public function setUp(): void
    {
        $this->testableSecurityEnabled = false;
        parent::setUp();
        $this->testableSecurityEnabled = true;
        $this->setupSecurity();

        $i18nConfiguration = $this->objectManager->get(I18nService::class)->getConfiguration();
        $this->localeToRestore = $i18nConfiguration->getCurrentLocale();
        $i18nConfiguration->setCurrentLocale(new Locale('en'));
        $this->requestHistory = [];
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        if ($this->localeToRestore !== null) {
            $this->objectManager->get(I18nService::class)->getConfiguration()->setCurrentLocale($this->localeToRestore);
        }
        if ($this->originalPushService !== null) {
            $this->objectManager->setInstance(AgentSigningKeyPushService::class, $this->originalPushService);
        }
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * From an alias the old label would have been the suffix-matching Domain record
     * (`https://example.com`); the label is the request base now, and the set carries every
     * site's Domain record next to it, signed by the pushed key over the canonical string.
     *
     * @test
     */
    public function aPushFromAnAliasHostLabelsTheInstallationWithTheRequestBaseAndCarriesEveryDomainRecordSigned(): void
    {
        $this->stubNeosidekick([$this->confirmedResponse(['https://www.example.com', 'https://example.com', 'https://example2.com'])]);
        $this->activateRequestOn('www.example.com');

        $result = $this->objectManager->get(AgentSigningKeyPushService::class)->pushIfNecessary();

        self::assertNotNull($result);
        self::assertTrue($result->successful, (string)$result->errorMessage);
        $body = $this->lastRequestBody();
        self::assertSame('https://www.example.com', $body['domain'], 'the request base, not the Domain record that suffix-matches it');
        self::assertSame(['https://www.example.com', 'https://example.com', 'https://example2.com'], $body['hosts']);
        self::assertEqualsWithDelta(time(), $body['hosts_signed_at'], 5);
        $signature = base64_decode((string)$body['hosts_signature'], true);
        self::assertNotFalse($signature);
        self::assertSame(1, openssl_verify(
            "neosidekick-hosts-v1\n" . self::FIXTURE_KID . "\n" . $body['hosts_signed_at'] . "\nhttps://www.example.com\nhttps://example.com\nhttps://example2.com\nhttps://www.example.com\n",
            $signature,
            (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem'),
            OPENSSL_ALGO_SHA256
        ), 'signed by the pushed key over the canonical string');
        self::assertSame(['https://www.example.com', 'https://example.com', 'https://example2.com'], $result->hosts, 'the stored set is echoed back');
        self::assertSame('accepted', $result->hostsResult);
    }

    /** @test */
    public function aPushFromTheSecondSiteLabelsTheInstallationWithThatSitesHostAndTheSameDomainRecords(): void
    {
        $this->stubNeosidekick([$this->confirmedResponse(['https://example.com', 'https://example2.com'])]);
        $this->activateRequestOn('example2.com');

        $result = $this->objectManager->get(AgentSigningKeyPushService::class)->pushIfNecessary();

        self::assertNotNull($result);
        self::assertTrue($result->successful, (string)$result->errorMessage);
        $body = $this->lastRequestBody();
        self::assertSame('https://example2.com', $body['domain']);
        self::assertSame(['https://example2.com', 'https://example.com'], $body['hosts'], 'the request host leads, one origin per host');
    }

    /**
     * The panel on each host: opening it pushes (forced past the daily cache) and renders the
     * answer - registered on both sites' hosts, not registered on the alias the stored set does
     * not name, with the remedy and the moved-site form only there.
     *
     * @test
     */
    public function thePanelReportsEachHostsStandingFromThePushItMakesOnLoad(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $storedSet = ['https://example.com', 'https://example2.com'];

        $this->stubNeosidekick([$this->confirmedResponse($storedSet)]);
        $onTheFirstSite = $this->getModuleIndexOn('example.com');
        self::assertCount(1, $this->requestHistory, 'opening the panel pushed');
        self::assertSame('https://example.com', $this->lastRequestBody()['domain']);
        self::assertSame(['https://example.com', 'https://example2.com'], $this->lastRequestBody()['hosts']);
        self::assertStringContainsString('data-signing-key-host-status="registered"', $onTheFirstSite);
        self::assertStringContainsString('NEOSidekick calls this installation at https://example.com; registered hosts: https://example.com, https://example2.com; this host: registered; last push result: accepted.', $onTheFirstSite);
        self::assertStringNotContainsString('moduleArguments[relabel]', $onTheFirstSite);
        self::assertStringNotContainsString('moduleArguments[reenrol]', $onTheFirstSite);
        self::assertStringContainsString('Regenerate key', $onTheFirstSite, 'regenerating is offered on every host');

        $this->seedSigningKeyRecord();
        $this->stubNeosidekick([$this->confirmedResponse($storedSet)]);
        $onTheSecondSite = $this->getModuleIndexOn('example2.com');
        self::assertSame('https://example2.com', $this->lastRequestBody()['domain']);
        self::assertStringContainsString('data-signing-key-host-status="registered"', $onTheSecondSite);
        self::assertStringContainsString('this host: registered;', $onTheSecondSite);
        self::assertStringNotContainsString('moduleArguments[relabel]', $onTheSecondSite);

        $this->seedSigningKeyRecord();
        $this->stubNeosidekick([$this->confirmedResponse($storedSet, 'base_host_unknown')]);
        $onTheAlias = $this->getModuleIndexOn('www.example.com');
        self::assertSame('https://www.example.com', $this->lastRequestBody()['domain']);
        self::assertStringContainsString('data-signing-key-host-status="not-registered"', $onTheAlias);
        self::assertStringContainsString('this host: not registered; last push result: rejected', $onTheAlias);
        self::assertStringContainsString('(base_host_unknown)', $onTheAlias);
        self::assertStringContainsString('The assistant is refused on www.example.com', $onTheAlias);
        self::assertStringContainsString('moduleArguments[relabel]', $onTheAlias, 'the moved-site answer');
        self::assertStringContainsString('moduleArguments[reenrol]', $onTheAlias, 'the copy answer');
        self::assertStringContainsString('Regenerate key', $onTheAlias);
    }

    /**
     * Within a minute of a successful push the forced push is throttled, and the panel then has
     * no answer to render: every host detail reads unknown, and no destructive answer is offered.
     *
     * @test
     */
    public function withinTheThrottleThePanelRendersTheHostStatusAsUnknown(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse(['https://example.com'])]);

        $this->getModuleIndexOn('example2.com');
        $again = $this->getModuleIndexOn('example2.com');

        self::assertCount(1, $this->requestHistory, 'the second load is throttled');
        self::assertStringContainsString('data-signing-key-host-status="unknown"', $again);
        self::assertStringContainsString('this host: unknown; last push result: unknown.', $again);
        self::assertStringNotContainsString('moduleArguments[relabel]', $again);
    }

    /**
     * Makes the given host the active HTTP request of this test process, the way Neos serves the
     * site: the collector reads the request base from it.
     */
    private function activateRequestOn(string $host): ServerRequest
    {
        $request = new ServerRequest('GET', 'https://' . $host . '/neos/ai-assistant/configuration');
        /** @var FunctionalTestRequestHandler $requestHandler */
        $requestHandler = self::$bootstrap->getActiveRequestHandler();
        $requestHandler->setHttpRequest($request);

        return $request;
    }

    /**
     * Renders the module through its real index action on the given host.
     */
    private function getModuleIndexOn(string $host): string
    {
        $arguments = ['moduleArguments' => ['@action' => 'index']];
        $httpRequest = $this->activateRequestOn($host)->withQueryParams($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }

        $response = new ActionResponse();
        $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest, $response);

        return (string)$response->getContent();
    }

    /**
     * Replaces the push service's HTTP client with canned NEOSidekick answers; the host collector
     * stays the real one, on the real Domain records and the active request. The module's
     * controller is a prototype the dispatcher builds per request, so replacing the singleton
     * service is what reaches it.
     *
     * @param array<int, mixed> $queuedResponses
     */
    private function stubNeosidekick(array $queuedResponses): void
    {
        $this->requestHistory = [];
        $handlerStack = HandlerStack::create(new MockHandler($queuedResponses));
        $handlerStack->push(Middleware::history($this->requestHistory));

        $stub = new class () extends AgentSigningKeyPushService {
            public ?Client $pushClient = null;

            protected function createPushClient(int $timeoutSeconds = 2): Client
            {
                return $this->pushClient ?? parent::createPushClient($timeoutSeconds);
            }
        };
        $stub->pushClient = new Client(['handler' => $handlerStack]);

        $internalHelper = $this->createMock(NEOSidekickInternalHelper::class);
        $internalHelper->method('pluginVersion')->willReturn('1.2.3');

        foreach ([
            'agentKeyPairService' => $this->objectManager->get(AgentKeyPairService::class),
            'agentSigningKeyRecordRepository' => $this->objectManager->get(AgentSigningKeyRecordRepository::class),
            'neosidekickInternalHelper' => $internalHelper,
            'agentInstallHostCollector' => $this->objectManager->get(AgentInstallHostCollector::class),
            'logger' => $this->createMock(LoggerInterface::class),
            'apiKey' => 'test-api-key',
            'externalApiDomain' => 'https://api.neosidekick.test',
        ] as $propertyName => $value) {
            $property = new ReflectionProperty(AgentSigningKeyPushService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($stub, $value);
        }

        $this->originalPushService ??= $this->objectManager->get(AgentSigningKeyPushService::class);
        $this->objectManager->setInstance(AgentSigningKeyPushService::class, $stub);
    }

    private function createBackendAccount(string $roleIdentifier): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('multi-site-push-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $account->setRoles([$this->objectManager->get(PolicyService::class)->getRole($roleIdentifier)]);
        $this->objectManager->get(AccountRepository::class)->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Multi', '', 'Site'));
        $user->getPreferences()->set('interfaceLanguage', 'en');
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }

    /**
     * @param array<int, string> $hosts The stored set the backend echoes
     */
    private function confirmedResponse(array $hosts, string $hostsResult = 'accepted'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'confirmed',
            'kid' => self::FIXTURE_KID,
            'fingerprint' => 'AA:BB',
            'install_root_kid' => 'root-kid-1',
            'domain' => $hosts[0],
            'hosts' => $hosts,
            'hosts_result' => $hostsResult,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRequestBody(): array
    {
        self::assertNotEmpty($this->requestHistory);
        /** @var Request $request */
        $request = $this->requestHistory[count($this->requestHistory) - 1]['request'];

        return json_decode((string)$request->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}

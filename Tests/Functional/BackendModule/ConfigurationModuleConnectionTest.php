<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\BackendModule;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Dispatcher;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Persistence\Doctrine\Exception\DatabaseStructureException;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Controller\BackendModule\ConfigurationController;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * What is left of the signing key on the Configuration module: one line when the connection needs
 * attention, a link to the key management module, and no control that changes a key. A healthy
 * connection says nothing at all. The module's Fusion is rendered for real. Everything that acts
 * on a key lives in {@see KeyManagementModuleTest}.
 */
class ConfigurationModuleConnectionTest extends FunctionalTestCase
{
    private const MODULE_URI = 'http://localhost/neos/ai-assistant/configuration';

    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $requestHistory = [];

    private ?AgentSigningKeyPushService $originalPushService = null;

    /**
     * The copy is asserted in English; the process-wide I18n configuration is put back afterwards
     * so no later test translates in the wrong language.
     */
    private ?Locale $localeToRestore = null;

    use SigningKeyRecordSeeding;

    public function setUp(): void
    {
        parent::setUp();
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
            $this->inject($this->objectManager->get(ConfigurationController::class), 'agentSigningKeyPushService', $this->originalPushService);
        }
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    #[Test]
    public function theModuleOffersNoKeyControlsAtAll(): void
    {
        $output = $this->renderConfigurationModule(true);

        foreach (['regenerateSigningKey', 'moduleArguments[reenrol]', 'moduleArguments[relabel]', 'data-signing-key-panel'] as $goneMarkup) {
            self::assertStringNotContainsString($goneMarkup, $output);
        }
        self::assertStringContainsString('<iframe', $output, 'the settings iframe is still there');
    }

    /**
     * A working connection is not worth a sentence - and with several accepted hosts, naming one
     * of them would be a wrong identity claim.
     */
    #[Test]
    public function aHealthyConnectionRendersNothingAtAll(): void
    {
        $output = $this->renderConfigurationModule(true);

        self::assertStringNotContainsString('data-connection-health', $output);
        self::assertStringNotContainsString('Connected to NEOSidekick', $output);
        self::assertStringNotContainsString('/neos/ai-assistant/keyManagement', $output);
    }

    #[Test]
    public function anUnhealthyConnectionStillSaysSoAndLinksToKeyManagement(): void
    {
        $output = $this->renderConfigurationModule(false);

        self::assertStringContainsString('data-connection-health="attention"', $output);
        self::assertStringContainsString('Connection needs attention.', $output);
        self::assertStringContainsString('/neos/ai-assistant/keyManagement', $output);

        self::assertStringNotContainsString('data-connection-health', $this->renderConfigurationModule(false, null, false), 'an editor sees no connection line');
    }

    #[Test]
    public function theEmbedTokenIsAppendedToTheIframeUrlOnlyWhenItWasMinted(): void
    {
        $token = 'eyJhbGciOiJSUzI1NiJ9.payload+slash/value.signature';

        $output = $this->renderConfigurationModule(false, $token);

        self::assertStringContainsString('&amp;embedToken=' . rawurlencode($token), $output);

        $withoutToken = $this->renderConfigurationModule(false, null);

        self::assertStringNotContainsString('embedToken', $withoutToken);
    }

    /**
     * The render is not a generation trigger: the module is open to every editor, and a keypair
     * nobody pushed to NEOSidekick would be an inert identity nobody asked for.
     */
    #[Test]
    public function openingTheModuleOnAKeylessInstallationCreatesNoRow(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->clearSigningKeyRecord();
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-2', 'first-kid')]);

        $output = $this->getModuleIndex();

        self::assertSame(0, $this->countSigningKeyRecords(), 'rendering the module must never mint a key');
        self::assertCount(0, $this->requestHistory, 'and the forced push does not run without a key');
        self::assertStringContainsString('<iframe', $output, 'the module renders all the same');
        self::assertStringContainsString('href="/neos/ai-assistant/keyManagement"', $output, 'the attention line links to the key management module, not into this one');
    }

    /**
     * The authorization flow is the one authenticated path that pushes right away, so the key
     * never exists without NEOSidekick learning of it. Having no predecessor, it is unchained.
     */
    #[Test]
    public function theAuthorizationPushOnAKeylessInstallationCreatesTheRowAndTransmitsItUnchained(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-2', 'first-kid')]);
        $this->clearSigningKeyRecord();

        $result = $this->objectManager->get(AgentSigningKeyPushService::class)->pushIfNecessary();

        self::assertNotNull($result, 'a keyless installation with a push target must push');
        self::assertTrue($result->successful);
        self::assertSame(1, $this->countSigningKeyRecords(), 'the authorization created the installation key');

        $body = json_decode((string)$this->requestHistory[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            trim((string)$this->readSigningKeyColumn('publickeypem')),
            $body['public_key_pem'],
            'the row was written before the push, and it is that key which was transmitted'
        );
        self::assertNull($body['chain_kid'] ?? null, 'a first key has no predecessor to chain to');
        self::assertSame(
            AgentKeyPairService::deriveKeyId((string)$this->readSigningKeyColumn('publickeypem')),
            $this->readSigningKeyColumn('pushkid')
        );
    }

    /**
     * The module is where an administrator looks for what is wrong, so a missing table must not
     * answer with a 500. The table is handed in as Flow's own DatabaseStructureException rather
     * than dropped, because Flow does not recognise SQLite's "no such table" wording.
     */
    #[Test]
    public function anInstallationWhoseMigrationHasNotRunRendersTheModuleInsteadOfFailing(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $repositoryWithoutTable = $this->createMock(AgentSigningKeyRecordRepository::class);
        $repositoryWithoutTable->method('findInstallRecord')->willThrowException(
            new DatabaseStructureException('A table or view seems to be missing from the database.', 1146)
        );

        $originalRepository = $this->replaceSigningKeyRecordRepository($repositoryWithoutTable);
        try {
            $output = $this->getModuleIndex();
        } finally {
            $this->replaceSigningKeyRecordRepository($originalRepository);
        }

        self::assertStringContainsString('<iframe', $output, 'the module is intact');
        self::assertStringNotContainsString('regenerateSigningKey', $output);
    }

    /**
     * Renders the module through its real index action, so the render path - and only it - is
     * what the assertions see.
     */
    private function getModuleIndex(): string
    {
        $arguments = ['moduleArguments' => ['@action' => 'index']];
        $httpRequest = (new ServerRequest('GET', self::MODULE_URI))->withQueryParams($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }

        return (string)$this->objectManager->get(Dispatcher::class)->dispatch($actionRequest)->getBody();
    }

    private function renderConfigurationModule(bool $connectionHealth, ?string $embedToken = null, bool $isAdministrator = true): string
    {
        $view = new FusionView();
        $view->setControllerContext($this->createControllerContext());
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule');
        $view->setFusionPath('NEOSidekick/AiAssistant/BackendModule/ConfigurationController/index');
        $view->assign('apiKey', 'test-api-key');
        $view->assign('apiDomain', 'https://api.neosidekick.com');
        $view->assign('siteDomain', '');
        $view->assign('embedToken', $embedToken);
        $view->assign('isAdministrator', $isAdministrator);
        $view->assign('connectionHealth', $connectionHealth);

        return (string)$view->render();
    }

    private function createControllerContext(): ControllerContext
    {
        $actionRequest = ActionRequest::fromHttpRequest(new ServerRequest('GET', 'https://neos.example.com/neos/management/ai-assistant/configuration'));
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($actionRequest);

        return new ControllerContext($actionRequest, new ActionResponse(), new Arguments(), $uriBuilder);
    }

    /**
     * Replaces the push service's HTTP client with a queue of canned NEOSidekick answers. The
     * double subclasses the proxied singleton, so Flow does not inject it - its collaborators
     * are copied over by hand.
     *
     * @param array<int, mixed> $queuedResponses
     */
    private function stubNeosidekick(array $queuedResponses): void
    {
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
        $hostCollector = $this->createMock(AgentInstallHostCollector::class);
        $hostCollector->method('resolveInstallOrigin')->willReturn('https://www.example.com');
        $hostCollector->method('collectHosts')->willReturn(['https://www.example.com']);

        foreach ([
            'agentKeyPairService' => $this->objectManager->get(AgentKeyPairService::class),
            'agentSigningKeyRecordRepository' => $this->objectManager->get(AgentSigningKeyRecordRepository::class),
            'neosidekickInternalHelper' => $internalHelper,
            'agentInstallHostCollector' => $hostCollector,
            'logger' => $this->createMock(LoggerInterface::class),
            'apiKey' => 'test-api-key',
            'externalApiDomain' => 'https://api.neosidekick.test',
        ] as $propertyName => $value) {
            $property = new ReflectionProperty(AgentSigningKeyPushService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($stub, $value);
        }

        $this->originalPushService = $this->objectManager->get(AgentSigningKeyPushService::class);
        $this->objectManager->setInstance(AgentSigningKeyPushService::class, $stub);
        $this->inject($this->objectManager->get(ConfigurationController::class), 'agentSigningKeyPushService', $stub);
    }

    private function createBackendAccount(string $roleIdentifier): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('configuration-module-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $account->setRoles([$this->objectManager->get(PolicyService::class)->getRole($roleIdentifier)]);
        $this->objectManager->get(AccountRepository::class)->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Module', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }

    private function confirmedResponse(string $installRootKid, string $kid): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'confirmed',
            'kid' => $kid,
            'fingerprint' => 'AA:BB',
            'install_root_kid' => $installRootKid,
        ], JSON_THROW_ON_ERROR));
    }
}

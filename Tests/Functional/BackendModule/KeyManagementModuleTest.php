<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\BackendModule;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Mvc\Dispatcher;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Neos\Flow\Security\Exception as SecurityException;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Controller\BackendModule\KeyManagementController;
use NEOSidekick\AiAssistant\Domain\Repository\AgentSigningKeyRecordRepository;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * The key management module's three actions, dispatched through Flow's dispatcher (firewall with
 * CSRF check, Neos' ModuleController, the module sub-request) against a stubbed NEOSidekick.
 *
 * The test browser is not used: every browser request opens a new session, whose security
 * context does not hold this test's CSRF token.
 */
class KeyManagementModuleTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    private const FIXTURE_KID = '6a6e0a3b6e0bc7a0127a00700b3edc6a952b722c60584ce959d54e82d683c334';

    private const MODULE_URI = 'http://localhost/neos/ai-assistant/keyManagement';

    private const MUTATING_ACTIONS = ['replaceKey', 'reenrol', 'relabel'];

    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $requestHistory = [];

    private ?AgentSigningKeyPushService $originalPushService = null;

    /**
     * Held from the moment the double was injected: by tear-down time the authenticated account
     * may be one that is not allowed to construct this controller at all.
     */
    private ?KeyManagementController $stubbedController = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->requestHistory = [];
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        if ($this->originalPushService !== null) {
            $this->objectManager->setInstance(AgentSigningKeyPushService::class, $this->originalPushService);
            if ($this->stubbedController !== null) {
                $this->inject($this->stubbedController, 'agentSigningKeyPushService', $this->originalPushService);
            }
        }
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * The privilege, not the menu entry, is what enforces this.
     */
    #[Test]
    public function anEditorIsDeniedTheWholeModule(): void
    {
        // The double is put in place as an administrator: an editor may not even construct the
        // controller, which is the very thing under test here.
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Editor'));

        foreach (['index' => 'GET', 'replaceKey' => 'POST', 'reenrol' => 'POST', 'relabel' => 'POST'] as $action => $method) {
            try {
                $method === 'POST' ? $this->post($action) : $this->get($action);
                self::fail('Expected the editor to be denied ' . $action);
            } catch (AccessDeniedException $exception) {
                self::assertStringContainsString('KeyManagementController', $exception->getMessage(), $action);
            }
        }

        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        self::assertFalse($this->objectManager->get(AgentKeyPairService::class)->hasPendingKeyPair());
        self::assertSame(self::FIXTURE_KID, $this->objectManager->get(AgentKeyPairService::class)->getKeyId());
    }

    /**
     * Flow's CSRF protection lets a GET through as a safe method, so the verb itself is the gate.
     * Neos 9's module controller passes only a redirect of a module response on, so the 405
     * arrives as its refusal text inside the module page.
     */
    #[Test]
    public function aGetOnEachMutatingActionIsRefusedAndChangesNothing(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        foreach (self::MUTATING_ACTIONS as $action) {
            $response = $this->get($action);
            self::assertSame('', $response->getHeaderLine('Location'), $action . ': no redirect back to a changed module');
            self::assertStringContainsString('The signing key is only changed on a POST from the module form.', (string)$response->getBody(), $action);
        }

        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'no successor was even prepared');
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the live pair is untouched');
    }

    /**
     * The leak answer end to end: announced chained to the live key, promoted on the 200.
     */
    #[Test]
    public function anAdministratorReplacesTheKeyThroughTheModule(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);

        $response = $this->post('replaceKey');

        self::assertStringContainsString('ai-assistant/keyManagement', $response->getHeaderLine('Location'), 'back to the module index');

        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair was promoted on the 200');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId());

        $body = $this->lastRequestBody();
        self::assertSame(trim($keyPairService->getPublicKeyPem()), $body['public_key_pem']);
        self::assertSame(self::FIXTURE_KID, $body['chain_kid'], 'chained to the previous live key');
        $signature = base64_decode((string)$body['chain_signature'], true);
        self::assertNotFalse($signature);
        self::assertSame(1, openssl_verify($body['public_key_pem'], $signature, (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem'), OPENSSL_ALGO_SHA256));
        self::assertSame('https://www.example.com', $body['domain']);
        self::assertSame(['https://www.example.com'], $body['hosts']);
        $hostSignature = base64_decode((string)$body['hosts_signature'], true);
        self::assertNotFalse($hostSignature);
        self::assertSame(1, openssl_verify(
            AgentSigningKeyPushService::canonicalHostString($body['kid'], $body['hosts_signed_at'], $body['domain'], $body['hosts']),
            $hostSignature,
            $body['public_key_pem'],
            OPENSSL_ALGO_SHA256
        ), 'the host set is signed by the pushed (pending) key');

        self::assertSame('root-kid-1', $this->readSigningKeyColumn('pushinstallrootkid'));
        self::assertSame($keyPairService->getKeyId(), $this->readSigningKeyColumn('pushkid'));
    }

    #[Test]
    public function theCopyAnswerAnnouncesTheKeyUnchainedWhereAReplacementStops(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->seedSigningKeyRecord(null, (string)file_get_contents(__DIR__ . '/../../Fixtures/agent-test-rotated-signing-key.pub.pem'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-9', 'new-kid')]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $this->post('replaceKey');

        self::assertCount(0, $this->requestHistory, 'nothing was announced to NEOSidekick');
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'no successor was even prepared');
        self::assertNull($this->readSigningKeyColumn('pushkid'));

        $this->post('reenrol');

        self::assertCount(1, $this->requestHistory);
        self::assertNull($this->lastRequestBody()['chain_kid'], 'a re-enrolment is announced unchained');
        self::assertNull($this->lastRequestBody()['chain_signature']);
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the successor is the live pair now');
        self::assertSame(trim((string)$this->readSigningKeyColumn('publickeypem')), $this->lastRequestBody()['public_key_pem']);
    }

    /**
     * The move disconnects the registered domain: it reads that domain from NEOSidekick first,
     * then rotates chained, keeping the identity.
     */
    #[Test]
    public function theMoveRegistersThisAddressAndKeepsTheIdentity(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([
            $this->registeredResponse('https://www.example.com'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $this->post('relabel');

        self::assertCount(2, $this->requestHistory, 'the verdict push, then the relabel');
        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the pending pair was promoted on the 200');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId());
        self::assertSame(self::FIXTURE_KID, $this->lastRequestBody()['chain_kid'], 'a move keeps the identity: it is chained');
    }

    /**
     * The rendered form posts the routing arguments in the query string and only the CSRF token
     * in the body, as a browser does; the move needs no field of its own.
     */
    #[Test]
    public function theMoveIsAcceptedFromTheRenderedFormPostingOnlyTheToken(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([
            $this->registeredResponse('https://www.example.com'),
            $this->confirmedResponse('root-kid-1', 'new-kid'),
        ]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $query = ['moduleArguments' => [
            '@package' => 'neosidekick.aiassistant',
            '@controller' => 'backendmodule\\keymanagement',
            '@action' => 'relabel',
            '@format' => 'html',
            '@subpackage' => '',
        ]];
        $body = ['__csrfToken' => $this->securityContext->getCsrfProtectionToken()];
        $httpRequest = (new ServerRequest('POST', self::MODULE_URI . '?' . http_build_query($query), ['Content-Type' => 'application/x-www-form-urlencoded']))
            ->withQueryParams($query)
            ->withParsedBody($body);
        $actionRequest = $this->route($httpRequest);
        foreach (\Neos\Utility\Arrays::arrayMergeRecursiveOverrule($query, $body) as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }
        $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest);

        self::assertCount(2, $this->requestHistory, 'the verdict push, then the relabel');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId(), 'the move was accepted');
    }

    /**
     * A pending pair is what the push would send, so a stale move POST would commit someone
     * else's successor as a side effect.
     */
    #[Test]
    public function theMoveIsRefusedWhileAKeyChangeIsPending(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([
            new Response(422, ['Content-Type' => 'application/json'], '{"error":"Signing key rejected.","reason":"chain_domain_mismatch"}'),
            $this->registeredResponse('https://www.example.com'),
        ]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);

        $this->post('replaceKey');
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'the rejected successor waits');

        $this->post('relabel');

        self::assertCount(1, $this->requestHistory, 'the move pushed nothing at all');
        self::assertTrue($keyPairService->hasPendingKeyPair(), 'and committed nothing');
        self::assertSame(self::FIXTURE_KID, $keyPairService->getKeyId());
    }

    /**
     * A successor NEOSidekick keeps refusing waits forever and blocks the replacement and the
     * move, so the page must still offer the copy answer - and that answer must get out.
     */
    #[Test]
    public function aRefusedPendingChangeStillOffersTheCopyAnswerAsTheWayOut(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $refusal = '{"error":"Signing key rejected.","reason":"chain_domain_mismatch"}';
        $this->stubNeosidekick([
            new Response(422, ['Content-Type' => 'application/json'], $refusal),
            new Response(422, ['Content-Type' => 'application/json'], $refusal),
            $this->confirmedResponse('root-kid-2', 'new-kid'),
        ]);
        $keyPairService = $this->objectManager->get(AgentKeyPairService::class);
        $this->post('replaceKey');

        $output = (string)$this->get('index')->getBody();

        self::assertStringContainsString('data-key-pending-banner="true"', $output);
        self::assertStringContainsString('data-key-form="reenrol"', $output, 'the copy answer is offered');
        self::assertMatchesRegularExpression('#formaction="[^"]*%40action%5D=reenrol#', $output);
        self::assertStringNotContainsString('data-key-form="replace-key"', $output);
        self::assertStringNotContainsString('data-key-form="relabel"', $output);

        $this->post('reenrol');

        self::assertFalse($keyPairService->hasPendingKeyPair(), 'the waiting successor is gone');
        self::assertNotSame(self::FIXTURE_KID, $keyPairService->getKeyId());
    }

    /**
     * Rendered for real through the index action, so a view variable the Fusion needs but the
     * controller does not assign fails here instead of in an administrator's browser.
     */
    #[Test]
    public function theIndexRendersTheQuestionBlockForAHostNEOSidekickDoesNotKnow(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->registeredResponse('https://www.example.com')]);

        $output = (string)$this->get('index')->getBody();

        self::assertCount(1, $this->requestHistory, 'opening the page pushed');
        self::assertStringContainsString('data-key-form="relabel"', (string)$output, 'the move is offered');
        self::assertStringContainsString('__csrfToken', (string)$output);
        self::assertStringContainsString('www.example.com', (string)$output, 'the registered host is named');
        self::assertStringContainsString('href="/neos/administration/sites"', (string)$output, 'answer 1 links to the Neos Sites module, not into this module');
        self::assertMatchesRegularExpression('#<form id="neos-sidekick-key-post" method="POST"[^>]*>\s*<input type="hidden" name="__csrfToken"#', (string)$output, 'the helper form carries the token');
        self::assertMatchesRegularExpression('#<button type="submit" form="neos-sidekick-key-post" formaction="[^"]*%40action%5D=relabel[^"]*"#', (string)$output, 'the move is confirmed in a modal that posts through the helper form');
    }

    /**
     * NEOSidekick's answer is rendered as text in the status, the answers and the confirmation
     * dialogs, so markup in it must arrive as text.
     */
    #[Test]
    public function markupInTheRegisteredDomainIsRenderedAsText(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->registeredResponse('https://www.<nsk-injected>.example.com')]);

        $output = (string)$this->get('index')->getBody();

        self::assertStringContainsString('data-key-block="host-unknown"', $output);
        self::assertStringNotContainsString('<nsk-injected>', $output);
        self::assertStringContainsString('&lt;nsk-injected&gt;', $output);
    }

    /**
     * The answers send the administrator elsewhere and back; a page restored from the back /
     * forward cache would show the verdict from before that trip. Neos 9's module controller
     * drops the module's Cache-Control header, so the page reloads itself instead.
     */
    #[Test]
    public function aPageRestoredFromTheBackForwardCacheReloadsItself(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->registeredResponse('https://www.example.com')]);

        $output = (string)$this->get('index')->getBody();

        self::assertMatchesRegularExpression('#<script src="[^"]*/BackendModule/KeyManagement\.js#', $output);
    }

    #[Test]
    public function anUnauthenticatedPostIsRefusedOnEveryMutatingAction(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->confirmedResponse('root-kid-1', 'new-kid')]);
        $csrfToken = $this->securityContext->getCsrfProtectionToken();
        $this->securityContext->clearContext();

        foreach (self::MUTATING_ACTIONS as $action) {
            try {
                $response = $this->post($action, [], $csrfToken);
                self::assertNotSame(200, $response->getStatusCode(), $action);
            } catch (SecurityException $exception) {
                // The firewall's own answer: authentication required, or the privilege refusing
                // the controller to a token that carries no role.
            }
        }

        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        self::assertSame(self::FIXTURE_KID, $this->objectManager->get(AgentKeyPairService::class)->getKeyId());
    }

    /**
     * Every action being matched by a privilege is what makes Flow apply the CSRF check to it.
     */
    #[Test]
    public function aPostWithoutTheCsrfTokenIsRefused(): void
    {
        $this->authenticateAccount($this->createBackendAccount('Neos.Neos:Administrator'));
        $this->stubNeosidekick([$this->registeredResponse('https://www.example.com')]);

        try {
            $response = $this->post('relabel', [], '');
            self::assertNotSame(200, $response->getStatusCode());
        } catch (SecurityException $exception) {
            self::assertStringContainsString('CSRF', $exception->getMessage());
        }

        self::assertCount(0, $this->requestHistory, 'nothing was pushed');
        self::assertSame(self::FIXTURE_KID, $this->objectManager->get(AgentKeyPairService::class)->getKeyId());
    }

    /**
     * @param array<string, string> $moduleArguments What the form's own fields add to the POST
     * @param string|null $csrfToken The token the form carries; null for this session's own
     * @throws AccessDeniedException When the privilege or the CSRF check denies the action
     */
    private function post(string $action, array $moduleArguments = [], ?string $csrfToken = null): ResponseInterface
    {
        $arguments = [
            'moduleArguments' => array_merge(['@action' => $action], $moduleArguments),
            '__csrfToken' => $csrfToken ?? $this->securityContext->getCsrfProtectionToken(),
        ];
        $httpRequest = (new ServerRequest('POST', self::MODULE_URI, ['Content-Type' => 'application/x-www-form-urlencoded']))->withParsedBody($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }
        self::assertSame('Neos\\Neos\\Controller\\Backend\\ModuleController', $actionRequest->getControllerObjectName(), 'the Neos module route');

        return $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest);
    }

    /**
     * The same module action reached with the verb a crafted link would use.
     */
    private function get(string $action): ResponseInterface
    {
        $arguments = ['moduleArguments' => ['@action' => $action]];
        $httpRequest = (new ServerRequest('GET', self::MODULE_URI))->withQueryParams($arguments);
        $actionRequest = $this->route($httpRequest);
        foreach ($arguments as $argumentName => $value) {
            $actionRequest->setArgument($argumentName, $value);
        }

        return $this->objectManager->get(Dispatcher::class)->dispatch($actionRequest);
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
        $this->stubbedController = $this->objectManager->get(KeyManagementController::class);
        $this->inject($this->stubbedController, 'agentSigningKeyPushService', $stub);
    }

    private function createBackendAccount(string $roleIdentifier): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('key-management-test-' . md5(uniqid('', true)));
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

    /**
     * What NEOSidekick answers a push it accepts from a host it does not know: the label it has
     * this installation registered under, and the reason it refused the pushed set.
     */
    private function registeredResponse(string $registeredDomain): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'confirmed',
            'kid' => self::FIXTURE_KID,
            'fingerprint' => 'AA:BB',
            'install_root_kid' => 'root-kid-1',
            'domain' => $registeredDomain,
            'hosts' => [$registeredDomain],
            'hosts_result' => 'base_host_unknown',
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

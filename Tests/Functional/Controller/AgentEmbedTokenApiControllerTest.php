<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Controller;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\Service\AgentKeyPairService;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use NEOSidekick\AiAssistant\Tests\Functional\SigningKeyRecordSeeding;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the HTTP contract of POST neosidekick/api/agentic/embed-token through the full
 * routing/security/dispatch stack - the parent-frame JS in the Neos UI plugin and the Laravel
 * embed gate are built against exactly this.
 */
class AgentEmbedTokenApiControllerTest extends FunctionalTestCase
{
    use SigningKeyRecordSeeding;

    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;


    protected AccountRepository $accountRepository;

    private ?AgentSigningKeyPushService $originalPushService = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->accountRepository = $this->objectManager->get(AccountRepository::class);
        $this->seedSigningKeyRecord();
    }

    public function tearDown(): void
    {
        if ($this->originalPushService !== null) {
            $this->objectManager->setInstance(AgentSigningKeyPushService::class, $this->originalPushService);
            $this->originalPushService = null;
        }
        $this->clearSigningKeyRecord();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function anAuthenticatedBackendSessionReceivesAValidEmbedToken(): void
    {
        [$account, $user] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);

        $response = $this->requestEmbedToken();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));

        $body = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['embed_token'], array_keys($body));

        $publicKeyPem = file_get_contents(__DIR__ . '/../../Fixtures/agent-test-signing-key.pub.pem');
        $claims = (array)JWT::decode($body['embed_token'], new Key($publicKeyPem, 'RS256'));

        self::assertSame(
            ['purpose', 'user_id', 'jti', 'iat', 'exp'],
            array_keys($claims),
            'the embed token claim set is pinned - nothing more, nothing less'
        );
        self::assertSame('embed', $claims['purpose']);
        self::assertSame(sha1($this->persistenceManager->getIdentifierByObject($user)), $claims['user_id']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $claims['jti']);
        self::assertIsInt($claims['iat']);
        self::assertIsInt($claims['exp']);
        self::assertSame(AgentTokenService::EMBED_TOKEN_LIFETIME, $claims['exp'] - $claims['iat']);
        self::assertEqualsWithDelta(time(), $claims['iat'], 5);

        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $body['embed_token'])[0]), true);
        self::assertSame('RS256', $header['alg']);
        self::assertSame(AgentKeyPairService::deriveKeyId($publicKeyPem), $header['kid']);
    }

    /**
     * @test
     */
    public function everyMintedEmbedTokenCarriesAFreshJti(): void
    {
        [$account] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);

        $firstJti = $this->decodeUnverifiedClaims($this->fetchEmbedTokenString())['jti'];
        $secondJti = $this->decodeUnverifiedClaims($this->fetchEmbedTokenString())['jti'];

        self::assertNotSame($firstJti, $secondJti, 'the jti is single-use server-side, so every mint must be fresh');
    }

    /**
     * Flow stores only safe GETs as the intercepted request for the after-login redirect, so an
     * editor whose session died mid-use would otherwise log back in onto a JSON blob.
     *
     * @test
     */
    public function aGetRequestDoesNotReachTheEndpoint(): void
    {
        [$account] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);

        $request = new ServerRequest(
            'GET',
            'http://localhost/neosidekick/api/agentic/embed-token',
            ['Accept' => 'application/json']
        );
        $response = $this->browser->sendRequest($request);

        self::assertNotSame(200, $response->getStatusCode());
        self::assertStringNotContainsString('embed_token', (string)$response->getBody());
    }

    /**
     * @test
     */
    public function anUnauthenticatedRequestIsDenied(): void
    {
        $response = $this->requestEmbedToken();

        self::assertContains($response->getStatusCode(), [401, 403], 'the endpoint must require a backend session');
        self::assertStringNotContainsString('embed_token', (string)$response->getBody());
    }

    /**
     * The identity claim must never be spoofable through request input.
     *
     * @test
     */
    public function theUserIdClaimComesFromTheSessionNotFromRequestParameters(): void
    {
        [$account, $user] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);

        $spoofedUserId = sha1('a-completely-different-editor');
        $response = $this->requestEmbedToken(
            '?userId=' . urlencode($spoofedUserId) . '&user_id=' . urlencode($spoofedUserId)
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        $claims = $this->decodeUnverifiedClaims($body['embed_token']);

        self::assertSame(sha1($this->persistenceManager->getIdentifierByObject($user)), $claims['user_id']);
        self::assertNotSame($spoofedUserId, $claims['user_id']);
    }

    /**
     * Whatever broke the mint (here a stored private key that is not a PEM) is detail disclosure
     * in an editor's browser, so only the labeled shape and a reference id may reach it.
     *
     * @test
     */
    public function aFailedMintReturnsTheGenericMessageInsteadOfTheUnderlyingFailure(): void
    {
        [$account] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);

        $this->seedSigningKeyRecord('not a pem at all');

        $response = $this->requestEmbedToken();

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('Internal Server Error', $body['error']);
        self::assertMatchesRegularExpression('/^Failed to generate embed token\. Reference: [0-9a-f]{8}$/', $body['message']);
        self::assertStringNotContainsString('embed_token', (string)$response->getBody());
    }

    /**
     * The handshake is a push path, but only when the request carries a JSON body: the plugin's
     * plain first-load fetch never pushes, while the assistant's gate handshake always posts
     * {"forcePush": <bool>} - false on its automatic attempt, true on "Try again". The action is
     * CSRF-exempt, so the body is honoured only under a content type a cross-site simple request
     * cannot carry, and only as a JSON object.
     *
     * @return array<string, array{0: array<string, string>, 1: string|null, 2: bool|null, 3?: string}>
     */
    public static function pushTriggerProvider(): array
    {
        $json = ['Content-Type' => 'application/json'];

        return [
            'no body: no push' => [[], null, null],
            'unforced handshake' => [$json, '{"forcePush": false}', false],
            'forced handshake' => [$json, '{"forcePush": true}', true],
            'forced handshake with a charset parameter' => [['Content-Type' => 'application/json; charset=utf-8'], '{"forcePush": true}', true],
            'a JSON object with a truthy non-boolean is unforced' => [$json, '{"forcePush": "true"}', false],
            'a JSON object without the flag is unforced' => [$json, '{"other": 1}', false],
            'text/plain: no push' => [['Content-Type' => 'text/plain'], '{"forcePush": true}', null],
            'form-encoded: no push' => [['Content-Type' => 'application/x-www-form-urlencoded'], 'forcePush=true', null],
            'invalid JSON: no push' => [$json, 'not json', null],
            'a JSON list: no push' => [$json, '[true]', null],
            'a JSON scalar: no push' => [$json, 'true', null],
            'query string only: no push' => [[], null, null, '?forcePush=1'],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param bool|null $expectedForceArgument Null when pushIfNecessary() must not be called
     * @test
     * @dataProvider pushTriggerProvider
     */
    public function theSigningKeyIsPushedOnlyForAJsonBodyAndTheTokenIsMintedEitherWay(array $headers, ?string $body, ?bool $expectedForceArgument, string $queryString = ''): void
    {
        [$account] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);
        $pushService = $this->stubPushService();

        $response = $this->requestEmbedToken($queryString, $headers, $body);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('embed_token', json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR));
        self::assertSame($expectedForceArgument === null ? [] : [$expectedForceArgument], $pushService->forceArguments);
    }

    /**
     * @test
     */
    public function aFailingPushNeverBlocksTheMint(): void
    {
        [$account] = $this->createEditorAccountWithUser();
        $this->authenticateAccount($account);
        $pushService = $this->stubPushService();
        $pushService->failure = new \RuntimeException('NEOSidekick is unreachable', 1757000099);

        $response = $this->requestEmbedToken('', ['Content-Type' => 'application/json'], '{"forcePush": true}');

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('embed_token', json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR));
        self::assertSame([true], $pushService->forceArguments, 'the push was attempted');
    }

    /**
     * Replaces the push service the controller is built with by a recorder that never pushes -
     * the controller's contract is that it calls it, with the right flag, and survives it.
     */
    private function stubPushService(): AgentSigningKeyPushService
    {
        $stub = new class () extends AgentSigningKeyPushService {
            /**
             * @var array<int, bool>
             */
            public array $forceArguments = [];

            public ?\Throwable $failure = null;

            public function pushIfNecessary(bool $force = false): ?AgentSigningKeyPushResult
            {
                $this->forceArguments[] = $force;
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return null;
            }
        };

        $this->originalPushService ??= $this->objectManager->get(AgentSigningKeyPushService::class);
        $this->objectManager->setInstance(AgentSigningKeyPushService::class, $stub);

        return $stub;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function requestEmbedToken(string $queryString = '', array $headers = [], ?string $body = null): ResponseInterface
    {
        $request = new ServerRequest(
            'POST',
            'http://localhost/neosidekick/api/agentic/embed-token' . $queryString,
            array_merge(['Accept' => 'application/json'], $headers),
            $body
        );

        return $this->browser->sendRequest($request);
    }

    protected function fetchEmbedTokenString(): string
    {
        $response = $this->requestEmbedToken();
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        return $body['embed_token'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeUnverifiedClaims(string $jwt): array
    {
        return (array)json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[1]), true);
    }

    /**
     * @return array{0: Account, 1: User}
     */
    protected function createEditorAccountWithUser(): array
    {
        $account = new Account();
        $account->setAccountIdentifier('embed-token-http-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $account->setRoles([$this->policyService->getRole('Neos.Neos:Editor')]);
        $this->accountRepository->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Embed', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return [$account, $user];
    }
}

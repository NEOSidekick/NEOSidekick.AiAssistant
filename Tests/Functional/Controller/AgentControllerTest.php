<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Service as I18nService;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\AccountRepository;
use Neos\Flow\Security\Policy\PolicyService;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Domain\Model\User;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Repository\PartyRepository;
use Neos\Party\Domain\Service\PartyService;

/**
 * The consent screen through the full routing/security/Fusion stack: the chat variant must stay
 * exactly what it was, and the external variant must name the calling application without ever
 * letting its self-chosen name become markup.
 */
class AgentControllerTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    protected $testableSecurityEnabled = true;

    /**
     * The browser-facing Laravel origin the decline link must point at - read from the very
     * setting the controller injects, never hard-coded.
     */
    protected string $apiDomain;

    protected const CONSUMER = 'external-9f1c3d2e';

    public function setUp(): void
    {
        parent::setUp();
        $this->apiDomain = rtrim((string)$this->objectManager->get(ConfigurationManager::class)->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'NEOSidekick.AiAssistant.Internal.apiDomain'
        ), '/');
    }

    /**
     * The controller\'s BackendUserTranslationTrait writes the acting user\'s language into the
     * process-wide I18n configuration and never puts it back, so a test that rendered for a French
     * editor would leave every later test translating in French.
     */
    protected ?Locale $localeToRestore = null;

    public function tearDown(): void
    {
        if ($this->localeToRestore !== null) {
            $this->objectManager->get(I18nService::class)->getConfiguration()->setCurrentLocale($this->localeToRestore);
            $this->localeToRestore = null;
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function theChatConsentPageCarriesNoExternalMarkupAndPostsOnlyTheStateAndTheCsrfToken(): void
    {
        $body = $this->renderConsentPage(['state' => 'laravel-state']);

        self::assertSame(
            ['__csrfToken', 'state'],
            $this->extractFormFieldNames($body),
            'the chat consent form must keep posting nothing but the state and the CSRF token'
        );
        self::assertStringNotContainsString('consumer', $body);
        self::assertStringNotContainsString('/oauth/authorized', $body, 'the chat variant has no decline link');
    }

    /**
     * @test
     */
    public function theExternalConsentPageNamesTheClientAndTheRedirectHostAndEchoesTheConsumer(): void
    {
        $body = $this->renderConsentPage([
            'state' => 'laravel-state',
            'consumer' => self::CONSUMER,
            'client_name' => 'Claude Code',
            'redirect_host' => 'claude.ai',
        ]);

        self::assertSame(['__csrfToken', 'state', 'consumer'], $this->extractFormFieldNames($body));
        self::assertStringContainsString('value="' . self::CONSUMER . '"', $body);
        self::assertStringContainsString('Claude Code', $body);
        self::assertStringContainsString('claude.ai', $body);
        self::assertStringContainsString('Consent Tester', $body, 'the acting identity is the editor, not the client');
    }

    /**
     * The consent page answers any hand-crafted query, so the values Laravel sanitized at client
     * registration are re-sanitized here.
     *
     * @test
     */
    public function theExternalConsentPageEscapesTheClientNameAndStripsControlCharacters(): void
    {
        $body = $this->renderConsentPage([
            'state' => 'laravel-state',
            'consumer' => self::CONSUMER,
            'client_name' => "Ev\u{202E}i\u{200B}l\u{0085}<script>alert(1)</script>\x07",
            'redirect_host' => "evil\u{200F}.example\x00.test",
        ]);

        self::assertStringNotContainsString('<script>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringNotContainsString("\u{202E}", $body);
        self::assertStringNotContainsString("\u{200F}", $body);
        self::assertStringNotContainsString("\u{200B}", $body);
        self::assertStringNotContainsString("\u{0085}", $body);
        self::assertStringNotContainsString("\x07", $body);
        self::assertStringContainsString('Evil', $body);
        self::assertStringContainsString('evil.example.test', $body);
    }

    /**
     * A client registered without a name - or whose name is nothing but characters the sanitizer
     * strips - must not be rendered as an empty pair of quotes.
     *
     * @test
     */
    public function theExternalConsentPageFallsBackToANeutralLabelForAnEmptyClientName(): void
    {
        $body = $this->renderConsentPageInInterfaceLanguage('en', [
            'state' => 'laravel-state',
            'consumer' => self::CONSUMER,
            'client_name' => "\u{200B}\x07 ",
            'redirect_host' => 'claude.ai',
        ]);

        self::assertStringContainsString('Unnamed client', $body);
        self::assertStringNotContainsString('itself &quot;&quot;', $body);
        self::assertStringNotContainsString('itself ""', $body);
    }

    /**
     * Declining mints nothing and carries no CSRF token: it is a plain navigation back to the
     * browser-facing Laravel origin.
     *
     * @test
     */
    public function theExternalConsentPageOffersAPlainDeclineLink(): void
    {
        $body = $this->renderConsentPage([
            'state' => 'laravel state/&',
            'consumer' => self::CONSUMER,
            'client_name' => 'Claude Code',
            'redirect_host' => 'claude.ai',
        ]);

        self::assertSame(
            1,
            preg_match('#<a href="([^"]*oauth/authorized[^"]*)"#', $body, $matches),
            'the external consent page must offer exactly one decline link: ' . $body
        );
        self::assertSame(
            $this->apiDomain . '/oauth/authorized?state=laravel%20state%2F%26&error=access_denied',
            html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * After Flow's login interception Neos's LoginController rebuilds the intercepted GET through
     * the router with the request's own arguments. The route must let every consent argument ride
     * along, or the not-logged-in first run of an external client dies in a 404 right after login.
     *
     * @test
     */
    public function theConsentPageRouteResolvesWithEveryConsentArgumentAfterALoginInterception(): void
    {
        $arguments = [
            'state' => 'laravel-state',
            'consumer' => self::CONSUMER,
            'client_name' => 'Claude Code',
            'redirect_host' => '127.0.0.1:48731',
        ];

        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest(ActionRequest::fromHttpRequest(new ServerRequest('GET', 'http://localhost/neos/login')));
        $uri = $uriBuilder->reset()->setCreateAbsoluteUri(true)->uriFor('index', $arguments, 'Agent', 'NEOSidekick.AiAssistant');

        self::assertStringStartsWith('http://localhost/neosidekick/agent/request-authorization?', $uri);
        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        self::assertSame($arguments, $query);
    }

    /**
     * French editors must not land on an English consent screen: the page renders in the interface
     * language of the backend user who is about to authorize.
     *
     * @test
     */
    public function theChatConsentPageRendersInFrench(): void
    {
        $body = $this->renderConsentPageInInterfaceLanguage('fr', ['state' => 'laravel-state']);

        self::assertStringContainsString('Autorise NEOSidekick à travailler avec toi', $body);
        self::assertStringContainsString('Autorisation de l\'agent NEOSidekick', $body);
        self::assertStringContainsString('Autoriser', $body);
        self::assertStringNotContainsString('Allow NEOSidekick to work together with you', $body);
    }

    /**
     * The external variant carries the units that name the calling application, so it is asserted
     * separately from the chat variant.
     *
     * @test
     */
    public function theExternalConsentPageRendersInFrench(): void
    {
        $body = $this->renderConsentPageInInterfaceLanguage('fr', [
            'state' => 'laravel-state',
            'consumer' => self::CONSUMER,
            'client_name' => 'Claude Code',
            'redirect_host' => 'claude.ai',
        ]);

        self::assertStringContainsString('Connecter une application externe', $body);
        self::assertStringContainsString('« Claude Code »', $body);
        self::assertStringContainsString('en tant que Consent Tester', $body);
        self::assertStringContainsString('vers claude.ai', $body);
        self::assertStringContainsString('Autoriser l\'accès', $body);
        self::assertStringContainsString('Annuler et refuser l\'accès', $body);
        self::assertStringNotContainsString('Connect an external application', $body);
    }

    /**
     * The German catalogue is the sibling of the French one, so both are exercised through the full
     * Fusion stack and not only through the catalogue parity unit test.
     *
     * @test
     */
    public function theChatConsentPageRendersInGerman(): void
    {
        $body = $this->renderConsentPageInInterfaceLanguage('de', ['state' => 'laravel-state']);

        self::assertStringContainsString('Erlaube NEOSidekick, mit dir zusammenzuarbeiten', $body);
        self::assertStringNotContainsString('Allow NEOSidekick to work together with you', $body);
    }

    /**
     * @param array<string, string> $query
     */
    protected function renderConsentPageInInterfaceLanguage(string $interfaceLanguage, array $query): string
    {
        $configuration = $this->objectManager->get(I18nService::class)->getConfiguration();
        $this->localeToRestore ??= $configuration->getCurrentLocale();

        $account = $this->createEditorAccountWithUser();
        $user = $this->objectManager->get(PartyService::class)->getAssignedPartyOfAccount($account);
        self::assertInstanceOf(User::class, $user);
        $user->getPreferences()->set('interfaceLanguage', $interfaceLanguage);
        $this->objectManager->get(PartyRepository::class)->update($user);
        $this->persistenceManager->persistAll();

        $this->authenticateAccount($account);

        $uri = 'http://localhost/neosidekick/agent/request-authorization?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $request = (new ServerRequest('GET', $uri))->withQueryParams($query);

        $response = $this->browser->sendRequest($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        return (string)$response->getBody();
    }

    /**
     * @param array<string, string> $query
     */
    protected function renderConsentPage(array $query): string
    {
        $this->authenticateAccount($this->createEditorAccountWithUser());

        $uri = 'http://localhost/neosidekick/agent/request-authorization?'
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        // Guzzle's ServerRequest does not derive the query params from the URI, and Flow maps
        // arguments from getQueryParams().
        $request = (new ServerRequest('GET', $uri))->withQueryParams($query);

        $response = $this->browser->sendRequest($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        return (string)$response->getBody();
    }

    /**
     * @return array<int, string>
     */
    protected function extractFormFieldNames(string $body): array
    {
        preg_match_all('/<input[^>]+name="([^"]+)"/', $body, $matches);

        return $matches[1];
    }

    protected function createEditorAccountWithUser(): Account
    {
        $account = new Account();
        $account->setAccountIdentifier('consent-page-test-' . md5(uniqid('', true)));
        $account->setAuthenticationProviderName('Neos.Neos:Backend');
        $account->setRoles([$this->objectManager->get(PolicyService::class)->getRole('Neos.Neos:Editor')]);
        $this->objectManager->get(AccountRepository::class)->add($account);

        $user = new User();
        $user->setName(new PersonName('', 'Consent', '', 'Tester'));
        $this->objectManager->get(PartyRepository::class)->add($user);
        $this->persistenceManager->persistAll();
        $this->objectManager->get(PartyService::class)->assignAccountToParty($account, $user);
        $this->persistenceManager->persistAll();

        return $account;
    }
}

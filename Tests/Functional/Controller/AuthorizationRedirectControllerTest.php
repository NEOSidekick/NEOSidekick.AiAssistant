<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Controller;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Tests\FunctionalTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The bounce page is the first hop of an install whose SameSite session cookie is not sent on the
 * cross-site entry. Flow drops undeclared arguments, so an external consent hand-off that loses
 * anything here silently degrades into a chat consent - which is exactly what this pins.
 */
class AuthorizationRedirectControllerTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function theBouncePageForwardsTheWholeExternalConsentQuery(): void
    {
        $response = $this->requestBouncePage([
            'state' => 'laravel-state',
            'consumer' => 'external-9f1c',
            'client_name' => 'Claude Code',
            'redirect_host' => 'claude.ai',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $target = $this->extractAuthorizationUrl($response);
        self::assertStringStartsWith('/neosidekick/agent/request-authorization?', $target);

        parse_str(parse_url($target, PHP_URL_QUERY), $forwarded);
        self::assertSame([
            'state' => 'laravel-state',
            'consumer' => 'external-9f1c',
            'client_name' => 'Claude Code',
            'redirect_host' => 'claude.ai',
        ], $forwarded);

        // Every forwarded value is rebuilt into a URL, so none of them may be unbounded.
        $overlongTarget = $this->extractAuthorizationUrl($this->requestBouncePage([
            'state' => 'laravel-state',
            'client_name' => str_repeat('a', 500),
        ]));

        parse_str(parse_url($overlongTarget, PHP_URL_QUERY), $overlongForwarded);
        self::assertSame(str_repeat('a', 200), $overlongForwarded['client_name']);
    }

    /**
     * The chat flow carries nothing but the state, and must keep bouncing exactly as before.
     *
     * @test
     */
    public function theBouncePageForwardsOnlyWhatItWasGiven(): void
    {
        $target = $this->extractAuthorizationUrl($this->requestBouncePage(['state' => 'laravel-state']));

        self::assertSame('/neosidekick/agent/request-authorization?state=laravel-state', $target);

        $bareTarget = $this->extractAuthorizationUrl($this->requestBouncePage([]));

        self::assertSame('/neosidekick/agent/request-authorization', $bareTarget);
    }

    /**
     * @param array<string, string> $query
     */
    protected function requestBouncePage(array $query): ResponseInterface
    {
        $uri = 'http://localhost/neosidekick/agent/redirect-to-request-authorization';
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        // Guzzle's ServerRequest does not derive the query params from the URI, and Flow maps
        // arguments from getQueryParams().
        return $this->browser->sendRequest((new ServerRequest('GET', $uri))->withQueryParams($query));
    }

    protected function extractAuthorizationUrl(ResponseInterface $response): string
    {
        $body = (string)$response->getBody();
        self::assertSame(
            1,
            preg_match('/<a href="([^"]+)"/', $body, $matches),
            'the bounce page must render exactly one continuation link: ' . $body
        );

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }
}

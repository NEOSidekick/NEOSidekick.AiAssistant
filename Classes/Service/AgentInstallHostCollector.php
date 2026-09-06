<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\DomainRepository;
use Psr\Http\Message\UriInterface;

/**
 * The hosts this installation answers on, as origin strings (`scheme://host[:port]`), for the
 * signing-key push: the label NEOSidekick calls this installation at, and the host set it may
 * send editor tokens to.
 *
 * The label is Flow's configured `http.baseUri`, else the trusted-proxy corrected base URI of the
 * active request - never a Neos Domain record, whose suffix matching would label a staging clone
 * or a sibling site with another host's name. The set is the active Domain records of online
 * sites, the base URI and the request base, one origin per host.
 *
 * @Flow\Scope("singleton")
 */
class AgentInstallHostCollector
{
    /**
     * The backend refuses a longer list outright, so the collector caps it; the label's host and
     * the request host are kept ahead of the cut.
     */
    public const MAX_HOSTS = 50;

    /**
     * A Domain record's origin wins over the base URI's, which wins over the request base's,
     * so a host is never sent with two schemes or ports.
     */
    private const RANK_DOMAIN_RECORD = 3;

    private const RANK_BASE_URI = 2;

    private const RANK_REQUEST = 1;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="http.baseUri")
     * @var string|null
     */
    protected ?string $configuredBaseUri = null;

    /**
     * @Flow\Inject
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * The origin NEOSidekick registers this installation under: the configured base URI, else
     * the request base. Null when neither exists (the CLI without a configured base URI), which
     * refuses the push rather than registering a guess.
     */
    public function resolveInstallOrigin(): ?string
    {
        return $this->resolveBaseUriOrigin() ?? $this->resolveRequestOrigin();
    }

    /**
     * The origin of Flow's configured `http.baseUri`, null when none is configured.
     */
    public function resolveBaseUriOrigin(): ?string
    {
        if ($this->configuredBaseUri === null || trim($this->configuredBaseUri) === '') {
            return null;
        }

        return self::originOf($this->configuredBaseUri);
    }

    /**
     * The origin of the active HTTP request's base URI, null outside an HTTP request.
     */
    public function resolveRequestOrigin(): ?string
    {
        $requestUri = $this->activeRequestUri();
        if ($requestUri === null) {
            return null;
        }

        return self::originOfUri($requestUri);
    }

    /**
     * The origins this installation answers on, lowercased, one per host, at most
     * {@see MAX_HOSTS}: the active Domain records of online sites (a record without a scheme
     * takes the request's, a record with a port keeps it), the base URI origin and the request
     * base origin. Among two candidates for one host the Domain record wins over the base URI,
     * the base URI over the request base, and https over http among equals.
     *
     * @param string|null $pushedDomain The label being pushed; its origin joins the set when it
     *                                  came from elsewhere (an explicit CLI `--domain`), so the
     *                                  registered address is always a destination
     * @return array<int, string>
     */
    public function collectHosts(?string $pushedDomain = null): array
    {
        $candidates = [];
        $anchorHosts = [];

        $baseUriOrigin = $this->resolveBaseUriOrigin();
        if ($baseUriOrigin !== null) {
            $candidates[] = [$baseUriOrigin, self::RANK_BASE_URI];
            $anchorHosts[] = self::hostOf($baseUriOrigin);
        }

        $requestOrigin = $this->resolveRequestOrigin();
        if ($requestOrigin !== null) {
            $candidates[] = [$requestOrigin, self::RANK_REQUEST];
            $anchorHosts[] = self::hostOf($requestOrigin);
        }

        $pushedOrigin = $pushedDomain === null ? null : self::originOf($pushedDomain);
        if ($pushedOrigin !== null) {
            $candidates[] = [$pushedOrigin, self::RANK_BASE_URI];
            $anchorHosts[] = self::hostOf($pushedOrigin);
        }

        $requestScheme = $this->schemeForDomainRecords($requestOrigin, $baseUriOrigin);
        foreach ($this->activeDomainRecordsOfOnlineSites() as $domain) {
            $origin = self::originOfDomainRecord($domain, $requestScheme);
            if ($origin !== null) {
                $candidates[] = [$origin, self::RANK_DOMAIN_RECORD];
            }
        }

        $originPerHost = self::pickOneOriginPerHost($candidates);
        $anchored = [];
        foreach (array_unique($anchorHosts) as $anchorHost) {
            if (isset($originPerHost[$anchorHost])) {
                $anchored[] = $originPerHost[$anchorHost];
                unset($originPerHost[$anchorHost]);
            }
        }
        $remaining = array_values($originPerHost);
        sort($remaining, SORT_STRING);

        return array_slice(array_merge($anchored, $remaining), 0, self::MAX_HOSTS);
    }

    /**
     * @param array<int, array{0: string, 1: int}> $candidates Origin and source rank
     * @return array<string, string> The chosen origin per host
     */
    private static function pickOneOriginPerHost(array $candidates): array
    {
        usort($candidates, static function (array $left, array $right): int {
            if ($left[1] !== $right[1]) {
                return $right[1] <=> $left[1];
            }
            $leftIsHttps = str_starts_with($left[0], 'https://');
            $rightIsHttps = str_starts_with($right[0], 'https://');
            if ($leftIsHttps !== $rightIsHttps) {
                return $leftIsHttps ? -1 : 1;
            }

            return strcmp($left[0], $right[0]);
        });

        $originPerHost = [];
        foreach ($candidates as [$origin]) {
            $host = self::hostOf($origin);
            if (!isset($originPerHost[$host])) {
                $originPerHost[$host] = $origin;
            }
        }

        return $originPerHost;
    }

    /**
     * @return array<int, Domain>
     */
    private function activeDomainRecordsOfOnlineSites(): array
    {
        $records = [];
        foreach ($this->domainRepository->findAll()->toArray() as $domain) {
            if (!$domain instanceof Domain || !$domain->getActive()) {
                continue;
            }
            $site = $domain->getSite();
            if ($site === null || !$site->isOnline()) {
                continue;
            }
            $records[] = $domain;
        }

        return $records;
    }

    /**
     * A Domain record without a scheme is served on whatever scheme the request came in on; on
     * the CLI the configured base URI's scheme stands in, and https when there is neither.
     */
    private function schemeForDomainRecords(?string $requestOrigin, ?string $baseUriOrigin): string
    {
        foreach ([$requestOrigin, $baseUriOrigin] as $origin) {
            if ($origin !== null) {
                return (string)parse_url($origin, PHP_URL_SCHEME);
            }
        }

        return 'https';
    }

    private static function originOfDomainRecord(Domain $domain, string $requestScheme): ?string
    {
        $hostname = strtolower(trim((string)$domain->getHostname()));
        if ($hostname === '') {
            return null;
        }
        $scheme = strtolower(trim((string)$domain->getScheme())) ?: $requestScheme;
        $port = $domain->getPort();

        return $scheme . '://' . $hostname . ($port !== null && (int)$port > 0 ? ':' . (int)$port : '');
    }

    private function activeRequestUri(): ?UriInterface
    {
        $activeRequestHandler = $this->bootstrap->getActiveRequestHandler();
        if (!$activeRequestHandler instanceof HttpRequestHandlerInterface) {
            return null;
        }

        return RequestInformationHelper::generateBaseUri($activeRequestHandler->getHttpRequest());
    }

    /**
     * The origin (`scheme://host[:port]`, lowercased, no path) of a URL or a bare host; null
     * when no host can be read from it. An explicit port is kept as given, a default one is
     * dropped the way Flow's Uri drops it.
     */
    public static function originOf(string $urlOrHost): ?string
    {
        $candidate = trim($urlOrHost);
        if ($candidate === '') {
            return null;
        }
        if (!str_contains($candidate, '://')) {
            $candidate = 'https://' . $candidate;
        }

        try {
            return self::originOfUri(new Uri($candidate));
        } catch (InvalidArgumentException $exception) {
            return null;
        }
    }

    private static function originOfUri(UriInterface $uri): ?string
    {
        $host = strtolower($uri->getHost());
        $scheme = strtolower($uri->getScheme());
        if ($scheme === '' || !preg_match('/^(\\[[0-9a-f:.]+\\]|[a-z0-9][a-z0-9.\\-]*)$/', $host)) {
            return null;
        }
        $port = $uri->getPort();

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * The host part of an origin string, for the one-per-host rule.
     */
    private static function hostOf(string $origin): string
    {
        return (string)parse_url($origin, PHP_URL_HOST);
    }
}

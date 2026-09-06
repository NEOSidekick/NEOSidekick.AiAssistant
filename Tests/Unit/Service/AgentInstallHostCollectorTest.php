<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Core\RequestHandlerInterface;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use NEOSidekick\AiAssistant\Service\AgentInstallHostCollector;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The host set is NEOSidekick's egress allowlist for editor tokens, so what goes into it - and
 * which of two candidates for one host wins - is pinned here: active Domain records of all sites,
 * online or not, with their own scheme and port, the base URI, the request base; lowercased; one
 * origin per host with the Domain record over the base URI over the request base and https over
 * http among equals; capped. A record without a site is skipped. The label is the base URI, else
 * the request base, never a Domain record.
 */
class AgentInstallHostCollectorTest extends TestCase
{
    /** @test */
    public function theInstallOriginIsTheConfiguredBaseUriElseTheRequestBaseNeverADomainRecord(): void
    {
        $withBaseUri = $this->createCollector(
            'https://Www.Front.example/',
            'https://cms.example/neos/login',
            [$this->domainRecord('codeq.at', 'https')]
        );
        self::assertSame('https://www.front.example', $withBaseUri->resolveInstallOrigin());
        self::assertSame('https://www.front.example', $withBaseUri->resolveBaseUriOrigin());
        self::assertSame('https://cms.example', $withBaseUri->resolveRequestOrigin());

        $withoutBaseUri = $this->createCollector(null, 'https://academy-new.codeq.at:8443/neos/', [$this->domainRecord('codeq.at', 'https')]);
        self::assertSame('https://academy-new.codeq.at:8443', $withoutBaseUri->resolveInstallOrigin(), 'the request base with its port, not the suffix-matching Domain record');
        self::assertNull($withoutBaseUri->resolveBaseUriOrigin());

        $cli = $this->createCollector(null, null, [$this->domainRecord('codeq.at', 'https')]);
        self::assertNull($cli->resolveInstallOrigin(), 'a Domain record never stands in for the address');
        self::assertNull($cli->resolveRequestOrigin());
    }

    /** @test */
    public function aBaseUriWithoutAHostOrAnEmptyOneCountsAsNotConfigured(): void
    {
        self::assertNull($this->createCollector('   ', null, [])->resolveBaseUriOrigin());
        self::assertNull($this->createCollector('/just/a/path', null, [])->resolveBaseUriOrigin());
    }

    /** @test */
    public function theSetHoldsActiveDomainRecordsOfEverySiteWithTheirSchemeAndPortTheBaseUriAndTheRequestBase(): void
    {
        $collector = $this->createCollector('https://front.example', 'http://cms.example/neos', [
            $this->domainRecord('codeq.at', 'https', 443),
            $this->domainRecord('academy-new.codeq.at', 'http', 8080),
            $this->domainRecord('inactive.example', 'https', null, active: false),
            $this->domainRecord('offline.example', 'https', null, online: true, siteOnline: false),
            $this->domainRecord('orphan.example', 'https', null, hasSite: false),
        ]);

        $hosts = $collector->collectHosts('https://front.example');

        self::assertSame(
            ['https://front.example', 'http://cms.example', 'http://academy-new.codeq.at:8080', 'https://codeq.at:443', 'https://offline.example'],
            $hosts,
            'the label\'s and the request\'s host lead, the Domain records follow sorted; an offline site\'s host belongs to the installation too, while an inactive record and one without a site do not; an explicit :443 is kept as the record carries it'
        );
    }

    /** @test */
    public function aDomainRecordWithoutASchemeTakesTheRequestSchemeThenTheBaseUriSchemeThenHttps(): void
    {
        self::assertSame(
            ['http://cms.example', 'http://codeq.at'],
            $this->createCollector(null, 'http://cms.example/', [$this->domainRecord('codeq.at', null)])->collectHosts('http://cms.example')
        );
        self::assertSame(
            ['http://front.example', 'http://codeq.at'],
            $this->createCollector('http://front.example', null, [$this->domainRecord('codeq.at', null)])->collectHosts('http://front.example'),
            'on the CLI the base URI scheme stands in'
        );
        self::assertSame(
            ['https://codeq.at'],
            $this->createCollector(null, null, [$this->domainRecord('codeq.at', '')])->collectHosts(null),
            'without either, https'
        );
    }

    /** @test */
    public function everyOriginIsLowercasedAndCarriesNoPath(): void
    {
        $collector = $this->createCollector('HTTPS://Front.Example/sub/dir/', 'https://CMS.Example:8443/neos/', [$this->domainRecord('CodeQ.AT', 'HTTPS')]);

        self::assertSame(
            ['https://front.example', 'https://cms.example:8443', 'https://codeq.at'],
            $collector->collectHosts('https://front.example')
        );
    }

    /** @test */
    public function oneOriginPerHostADomainRecordWinsOverTheBaseUriWhichWinsOverTheRequestBase(): void
    {
        $domainWins = $this->createCollector('https://codeq.at', 'https://codeq.at:8443/neos', [$this->domainRecord('codeq.at', 'http', 8080)]);
        self::assertSame(['http://codeq.at:8080'], $domainWins->collectHosts('https://codeq.at'), 'the Domain record decides scheme and port');

        $baseUriWins = $this->createCollector('https://codeq.at:8443', 'http://codeq.at/neos', []);
        self::assertSame(['https://codeq.at:8443'], $baseUriWins->collectHosts('https://codeq.at:8443'));

        $requestOnly = $this->createCollector(null, 'http://codeq.at:8080/neos', []);
        self::assertSame(['http://codeq.at:8080'], $requestOnly->collectHosts('http://codeq.at:8080'));
    }

    /** @test */
    public function amongEqualsHttpsWinsOverHttp(): void
    {
        $twoRecords = $this->createCollector(null, null, [
            $this->domainRecord('codeq.at', 'http'),
            $this->domainRecord('codeq.at', 'https'),
        ]);
        self::assertSame(['https://codeq.at'], $twoRecords->collectHosts(null));

        $reversed = $this->createCollector(null, null, [
            $this->domainRecord('codeq.at', 'https', 443),
            $this->domainRecord('codeq.at', 'http', 80),
        ]);
        self::assertSame(['https://codeq.at:443'], $reversed->collectHosts(null), 'regardless of record order');
    }

    /**
     * An explicit `--domain` on the shell is the address NEOSidekick registers, so it is a
     * destination too; a Domain record for the same host still decides its origin.
     *
     * @test
     */
    public function anExplicitlyPushedDomainJoinsTheSetBehindADomainRecordOfTheSameHost(): void
    {
        $collector = $this->createCollector(null, null, [$this->domainRecord('codeq.at', 'https')]);

        self::assertSame(['https://www.codeq.at', 'https://codeq.at'], $collector->collectHosts('https://www.codeq.at/'));
        self::assertSame(['https://codeq.at'], $collector->collectHosts('http://codeq.at'), 'the Domain record wins over the pushed label');
        self::assertSame(['https://codeq.at'], $collector->collectHosts('not a domain'), 'an unreadable label adds nothing');
    }

    /** @test */
    public function withoutAnySourceTheSetIsEmpty(): void
    {
        self::assertSame([], $this->createCollector(null, null, [])->collectHosts(null));
    }

    /** @test */
    public function theSetIsCappedAtFiftyKeepingTheLabelAndTheRequestHostAheadOfTheCut(): void
    {
        $records = [];
        for ($index = 0; $index < 60; $index++) {
            $records[] = $this->domainRecord(sprintf('site-%02d.example', $index), 'https');
        }
        $collector = $this->createCollector('https://zzz-front.example', 'https://zzz-cms.example/neos', $records);

        $hosts = $collector->collectHosts('https://zzz-front.example');

        self::assertCount(AgentInstallHostCollector::MAX_HOSTS, $hosts);
        self::assertSame(['https://zzz-front.example', 'https://zzz-cms.example'], array_slice($hosts, 0, 2));
        self::assertSame('https://site-00.example', $hosts[2]);
        self::assertSame('https://site-47.example', $hosts[49]);
    }

    /** @test */
    public function originOfNormalizesAUrlOrABareHostAndRefusesTheUnreadable(): void
    {
        self::assertSame('https://codeq.at', AgentInstallHostCollector::originOf(' codeq.at '));
        self::assertSame('https://codeq.at', AgentInstallHostCollector::originOf('https://CodeQ.at:443/neos'), 'a URL drops its default port the way Flow\'s Uri does; only a Domain record keeps an explicit :443');
        self::assertSame('https://codeq.at:8443', AgentInstallHostCollector::originOf('https://CodeQ.at:8443/neos'));
        self::assertSame('http://codeq.at', AgentInstallHostCollector::originOf('http://codeq.at:80/'));
        self::assertNull(AgentInstallHostCollector::originOf(''));
        self::assertNull(AgentInstallHostCollector::originOf('https://'));
    }

    /**
     * @param array<int, Domain> $domainRecords
     */
    private function createCollector(?string $configuredBaseUri, ?string $requestUri, array $domainRecords): AgentInstallHostCollector
    {
        $queryResult = $this->createMock(QueryResultInterface::class);
        $queryResult->method('toArray')->willReturn($domainRecords);
        $domainRepository = $this->createMock(DomainRepository::class);
        $domainRepository->method('findAll')->willReturn($queryResult);

        $bootstrap = $this->createMock(Bootstrap::class);
        if ($requestUri === null) {
            $bootstrap->method('getActiveRequestHandler')->willReturn($this->createMock(RequestHandlerInterface::class));
        } else {
            $requestHandler = $this->createMock(HttpRequestHandlerInterface::class);
            $requestHandler->method('getHttpRequest')->willReturn(new ServerRequest('GET', $requestUri));
            $bootstrap->method('getActiveRequestHandler')->willReturn($requestHandler);
        }

        $collector = new AgentInstallHostCollector();
        foreach ([
            'configuredBaseUri' => $configuredBaseUri,
            'bootstrap' => $bootstrap,
            'domainRepository' => $domainRepository,
        ] as $propertyName => $value) {
            $property = new ReflectionProperty(AgentInstallHostCollector::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($collector, $value);
        }

        return $collector;
    }

    private function domainRecord(string $hostname, ?string $scheme, ?int $port = null, bool $active = true, bool $online = true, bool $siteOnline = true, bool $hasSite = true): Domain
    {
        $domain = new Domain();
        $domain->setHostname($hostname);
        $domain->setScheme($scheme);
        $domain->setPort($port);
        $domain->setActive($active);
        if ($hasSite) {
            $site = new Site('site-' . md5($hostname));
            $site->setState($online && $siteOnline ? Site::STATE_ONLINE : Site::STATE_OFFLINE);
            $domain->setSite($site);
        }

        return $domain;
    }
}

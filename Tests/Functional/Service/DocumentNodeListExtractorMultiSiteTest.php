<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Which site the document list is taken from on an installation with several sites: an explicitly
 * named one, else the site whose active Domain record matches the request host (its own record, a
 * suffix-matched one, and regardless of the site's state), else Neos's default site — here the
 * first online site, `example`. Only without a request host at all does the first site below the
 * sites root step in, with a log line.
 *
 * `example.com`, `example2.com` and `sub.example.com` are the three sites' Domain records; the
 * site of `sub.example.com` is offline, `other.example2.com` has no record of its own and is
 * served by `example2.com` through Neos's suffix matching.
 */
class DocumentNodeListExtractorMultiSiteTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com', 'example2.com', 'sub.example.com'];

    private ?LoggerInterface $originalLogger = null;

    public function setUp(): void
    {
        parent::setUp();

        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $offlineSite = $siteRepository->findOneByNodeName('sub');
        $offlineSite->setState(Site::STATE_OFFLINE);
        $siteRepository->update($offlineSite);
        $this->persistenceManager->persistAll();
    }

    public function tearDown(): void
    {
        if ($this->originalLogger !== null) {
            $this->inject($this->objectManager->get(DocumentNodeListExtractor::class), 'logger', $this->originalLogger);
            $this->originalLogger = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function anExplicitSiteNodeNameWinsOverTheRequestHost(): void
    {
        $result = $this->extract(siteNodeName: 'example2', requestHost: 'example.com');

        self::assertSame('example2', $result['site']['name']);
        self::assertSame(['example', 'example2', 'sub'], array_column($result['availableSites'], 'nodeName'));
    }

    #[Test]
    public function theRequestHostSelectsItsOwnSite(): void
    {
        self::assertSame('example2', $this->extract(requestHost: 'example2.com')['site']['name']);
        self::assertSame('example', $this->extract(requestHost: 'example.com')['site']['name']);
    }

    #[Test]
    public function theHostOfAnOfflineSiteStillSelectsThatSite(): void
    {
        self::assertSame(
            'sub',
            $this->extract(requestHost: 'sub.example.com')['site']['name'],
            'an editor working on a site that is not published yet is a normal backend workflow'
        );
    }

    #[Test]
    public function aHostWithoutItsOwnRecordIsServedBySuffixMatchTheWayNeosRoutesIt(): void
    {
        self::assertSame('example2', $this->extract(requestHost: 'other.example2.com')['site']['name']);
    }

    #[Test]
    public function aHostWithoutAnyMatchingRecordFallsBackToTheDefaultSiteOfNeos(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $this->injectLogger($logger);

        self::assertSame(
            'example',
            $this->extract(requestHost: 'nomatch.test')['site']['name'],
            'Neos names its default site — no configured one here, so the first online site'
        );
    }

    #[Test]
    public function anUnknownSiteNodeNameIsRefusedNamingTheAvailableSites(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No site "nope" found. Available sites: example, example2, sub');

        $this->extract(siteNodeName: 'nope', requestHost: 'example.com');
    }

    #[Test]
    public function aSiteNodeNameThatIsAPathIsRefusedNamingTheAvailableSites(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No site "example/.." found. Available sites: example, example2, sub');

        $this->extract(siteNodeName: 'example/..', requestHost: 'example.com');
    }

    #[Test]
    public function aSiteNodeNameIsMatchedRegardlessOfItsCase(): void
    {
        self::assertSame('example2', $this->extract(siteNodeName: 'EXAMPLE2')['site']['name']);
    }

    #[Test]
    public function withoutARequestHostTheFirstSiteIsUsedAndTheFallbackIsLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('info');
        $this->injectLogger($logger);

        $result = $this->extract();

        self::assertSame('example', $result['site']['name']);
        self::assertSame(
            [
                ['nodeName' => 'example', 'name' => 'example'],
                ['nodeName' => 'example2', 'name' => 'example2'],
                ['nodeName' => 'sub', 'name' => 'sub'],
            ],
            $result['availableSites'],
            'every site of the installation is listed so a caller can name another one'
        );
    }

    private function extract(?string $siteNodeName = null, ?string $requestHost = null): array
    {
        /** @var DocumentNodeListExtractor $extractor */
        $extractor = $this->objectManager->get(DocumentNodeListExtractor::class);
        $language = $this->primaryLanguage();

        return $extractor->extract(
            workspace: 'live',
            dimensions: $language === null ? [] : [$this->languageDimensionId()->value => [$language]],
            siteNodeName: $siteNodeName,
            nodeTypeFilter: 'Neos.Neos:Document',
            depth: -1,
            requestHost: $requestHost
        );
    }

    private function injectLogger(LoggerInterface $logger): void
    {
        $extractor = $this->objectManager->get(DocumentNodeListExtractor::class);
        if ($this->originalLogger === null) {
            $property = new ReflectionProperty(DocumentNodeListExtractor::class, 'logger');
            $property->setAccessible(true);
            $this->originalLogger = $property->getValue($extractor);
        }
        $this->inject($extractor, 'logger', $logger);
    }
}

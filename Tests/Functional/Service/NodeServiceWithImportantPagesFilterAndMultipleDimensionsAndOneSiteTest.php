<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceWithImportantPagesFilterAndMultipleDimensionsAndOneSiteTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $exampleSiteNode = $this->getNodeByPath('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg', 'image2.jpg']);
        $this->setNodeProperties($page1, ['focusKeyword' => 'some-value']);
        $page1a = $this->createPageWithImageNodes($page1, 'lady-eleonode-rootford', 'Unterseite 1', ['image1.jpg', 'image2.jpg']);
        $page2 = $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg', 'image2.jpg']);

        // English variants directly in live (on Neos 8 they were created in the user
        // workspace and published to live so that routing could resolve EN URIs — the
        // end state is identical).
        $this->createLanguageVariant($exampleSiteNode, self::LANGUAGE_EN);
        $this->createLanguageVariant($page1, self::LANGUAGE_EN);
        $this->createLanguageVariant($page1a, self::LANGUAGE_EN);
        $this->createLanguageVariant($page2, self::LANGUAGE_EN);
    }

    /**
     * Negative case: API returns a page that has a non-empty focus keyword, but filter is "only-empty-focus-keywords".
     * Expectation: it must NOT be returned.
     * @test
     */
    public function itFindsImportantPagesWithEmptyFocusKeyword(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();

        $candidates = [];
        $candidates[] = 'https://example.com/de/node-wan-kenodi' . $this->getUriPathSuffix();
        $candidates[] = 'https://example.com/de/node-wan-kenodi/lady-eleonode-rootford' . $this->getUriPathSuffix();
        $candidates[] = 'https://example.com/en/node-wan-kenodi' . $this->getUriPathSuffix();
        $candidates[] = 'https://example.com/en/node-wan-kenodi/lady-eleonode-rootford' . $this->getUriPathSuffix();

        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidates);
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-empty-focus-keywords',
            languageDimensionFilter: self::LANGUAGE_DE
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');

        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Only the German subpage without focus keyword should be returned');
        $expectedKey = $this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', 'live');
        $this->assertArrayHasKey($expectedKey, $foundNodes, 'German subpage without focus keyword must be present');
        $forbiddenKey = $this->addressForPath('/sites/example/node-wan-kenodi', 'live');
        foreach ($foundNodes as $dto) {
            $this->assertNotEquals($forbiddenKey, $dto->getNodeContextPath(), 'Page with non-empty focus keyword must be excluded when filtering for empty focus keyword.');
        }
    }

    /**
     * Positive case: API returns a page that has a non-empty focus keyword and filter requires existing focus keyword.
     * Expectation: the page should be returned.
     * @test
     */
    public function itFindsImportantPagesWithExistingFocusKeyword(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();

        $candidates = [];
        // Minimal, deterministic candidate list reflecting current implementation
        $candidates[] = 'https://example.com/de/node-wan-kenodi' . $this->getUriPathSuffix();

        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidates);

        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: self::LANGUAGE_DE
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Only the page with focus keyword should be returned');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes, 'German page with focus keyword must be present');
    }

    /**
     * URL with the configured uriPathSuffix (e.g. ".html") resolves directly.
     * @test
     */
    public function itResolvesUrlWithConfiguredUriPathSuffix(): void
    {
        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/de/node-wan-kenodi' . $this->getUriPathSuffix(),
        ]);

        $this->assertCount(1, $foundNodes, 'URL with configured suffix must resolve');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * A trailing-slash URL resolves because NodeFindingService strips the trailing
     * slash and retries with the configured suffix appended.
     * @test
     */
    public function itResolvesUrlWithTrailingSlash(): void
    {
        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/de/node-wan-kenodi/',
        ]);

        $this->assertCount(1, $foundNodes, 'Trailing-slash URL must resolve after suffix normalisation');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * A bare URL (no suffix, no trailing slash) resolves because NodeFindingService
     * retries with the configured suffix appended.
     * @test
     */
    public function itResolvesUrlWithoutAnySuffix(): void
    {
        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/de/node-wan-kenodi',
        ]);

        $this->assertCount(1, $foundNodes, 'Bare URL must resolve after suffix normalisation');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * All three URL variants (with suffix, trailing slash, bare) point to the same
     * node and must deduplicate to a single result.
     * @test
     */
    public function itDeduplicatesAllUrlVariantsToSameNode(): void
    {
        $base = 'https://example.com/de/node-wan-kenodi';
        $foundNodes = $this->findImportantPagesForCandidates([
            $base . $this->getUriPathSuffix(),
            $base . '/',
            $base,
        ]);

        $this->assertCount(1, $foundNodes, 'All URL variants for the same page must deduplicate to one node');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * Duplicate candidate URLs must map to a single unique node result.
     * @test
     */
    public function itDeduplicatesCandidates(): void
    {
        $url = 'https://example.com/de/node-wan-kenodi' . $this->getUriPathSuffix();
        $foundNodes = $this->findImportantPagesForCandidates([$url, $url, $url]);

        $this->assertCount(1, $foundNodes, 'Duplicate candidate URLs must deduplicate to one node');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * Verifies that uriMatchesControllerContext compares hostnames only, so that
     * scheme variations (http vs https) and default-port variations (:80, :443)
     * do not prevent a URL from resolving.
     * @test
     */
    public function itMatchesHostRegardlessOfSchemeAndDefaultPort(): void
    {
        $basePath = '/de/node-wan-kenodi' . $this->getUriPathSuffix();
        $foundNodes = $this->findImportantPagesForCandidates([
            'http://example.com' . $basePath,
            'http://example.com:80' . $basePath,
            'https://example.com' . $basePath,
            'https://example.com:443' . $basePath,
        ]);

        $this->assertCount(1, $foundNodes, 'Scheme/port variants must resolve to one node');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', 'live'), $foundNodes);
    }

    /**
     * @param string[] $candidateUrls
     * @return array
     */
    private function findImportantPagesForCandidates(array $candidateUrls): array
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidateUrls);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: self::LANGUAGE_DE
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
        $this->assertIsArray($foundNodes);
        return $foundNodes;
    }
}

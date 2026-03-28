<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeFindingService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceWithImportantPagesFilterAndMultipleDimensionsAndOneSiteTest extends FunctionalTestCase
{
    protected array $dimensions = ['de', 'en'];
    protected array $siteHosts = ['example.com'];

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg', 'image2.jpg']);
        $page1->setProperty('focusKeyword', 'some-value');
        $page1a = $this->createPageWithImageNodes($page1, 'lady-eleonode-rootford', 'Unterseite 1', ['image1.jpg', 'image2.jpg']);
        $page2 = $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg', 'image2.jpg']);

        $englishContext = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => ['language' => ['en']]
        ]);

        $exampleSiteNode->createVariantForContext($englishContext);
        $page1->createVariantForContext($englishContext);
        $page1a->createVariantForContext($englishContext);
        $page2->createVariantForContext($englishContext);

        // DE nodes are already in the live workspace (created via $this->rootNode which is in live context).
        // Publish EN variants from the user workspace so that routing can resolve EN URIs too.
        $englishContext->getWorkspace()->publish($this->liveWorkspace);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
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
        // Build public URI according to the current Testing routing configuration
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $candidates = [];
        $candidates[] = 'https://example.com/de/node-wan-kenodi' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/de/node-wan-kenodi/lady-eleonode-rootford' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/en/node-wan-kenodi' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/en/node-wan-kenodi/lady-eleonode-rootford' . $defaultUriSuffix;

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
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');

        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Only the German subpage without focus keyword should be returned');
        $routingDimensions = ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')];
        $expectedContextPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', 'live', $routingDimensions);
        $this->assertArrayHasKey($expectedContextPath, $foundNodes, 'German subpage without focus keyword must be present');
        $forbiddenContextPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', $routingDimensions);
        foreach ($foundNodes as $dto) {
            $this->assertNotEquals($forbiddenContextPath, $dto->getNodeContextPath(), 'Page with non-empty focus keyword must be excluded when filtering for empty focus keyword.');
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
        // Build public URI according to the current Testing routing configuration
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $candidates = [];
        // Minimal, deterministic candidate list reflecting current implementation
        $candidates[] = 'https://example.com/de/node-wan-kenodi' . $defaultUriSuffix;

        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidates);

        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Only the page with focus keyword should be returned');
        $expectedContextPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedContextPath, $foundNodes, 'German page with focus keyword must be present');
    }

    /**
     * URL with the configured uriPathSuffix (e.g. ".html") resolves directly.
     * @test
     */
    public function itResolvesUrlWithConfiguredUriPathSuffix(): void
    {
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/de/node-wan-kenodi' . $defaultUriSuffix,
        ]);

        $this->assertCount(1, $foundNodes, 'URL with configured suffix must resolve');
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
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
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
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
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
    }

    /**
     * All three URL variants (with suffix, trailing slash, bare) point to the same
     * node and must deduplicate to a single result.
     * @test
     */
    public function itDeduplicatesAllUrlVariantsToSameNode(): void
    {
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $base = 'https://example.com/de/node-wan-kenodi';
        $foundNodes = $this->findImportantPagesForCandidates([
            $base . $defaultUriSuffix,
            $base . '/',
            $base,
        ]);

        $this->assertCount(1, $foundNodes, 'All URL variants for the same page must deduplicate to one node');
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
    }

    /**
     * Duplicate candidate URLs must map to a single unique node result.
     * @test
     */
    public function itDeduplicatesCandidates(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $url = 'https://example.com/de/node-wan-kenodi' . $defaultUriSuffix;
        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn([$url, $url, $url]);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Duplicate candidate URLs must deduplicate to one node');
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
    }
    /**
     * Verifies that uriMatchesControllerContext compares hostnames only, so that
     * scheme variations (http vs https) and default-port variations (:80, :443)
     * do not prevent a URL from resolving.
     *
     * There is no explicit normalisation in the product code — this works because
     * parse_url() separates host from port, and the result map keys by context
     * path which naturally deduplicates identical nodes.
     * @test
     */
    public function itMatchesHostRegardlessOfSchemeAndDefaultPort(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        $basePath = '/de/node-wan-kenodi' . $defaultUriSuffix;
        $candidates = [
            'http://example.com' . $basePath,
            'http://example.com:80' . $basePath,
            'https://example.com' . $basePath,
            'https://example.com:443' . $basePath,
        ];
        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidates);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Scheme/port variants must resolve to one node');
        $expectedPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $this->getRoutingLanguageDimensionValuesForPreset('de')]);
        $this->assertArrayHasKey($expectedPath, $foundNodes);
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
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
        $this->assertIsArray($foundNodes);
        return $foundNodes;
    }
}

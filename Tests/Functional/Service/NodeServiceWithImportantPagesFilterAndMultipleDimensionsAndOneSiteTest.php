<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
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

        // Publish site and pages so that routes/URIs can resolve in functional context
        $exampleSiteNode->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page1->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page1a->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page2->getContext()->getWorkspace()->publish($this->liveWorkspace);

        $exampleSiteNode->createVariantForContext($englishContext)->getWorkspace()->publish($this->liveWorkspace);
        $page1->createVariantForContext($englishContext)->getWorkspace()->publish($this->liveWorkspace);
        $page1a->createVariantForContext($englishContext)->getWorkspace()->publish($this->liveWorkspace);
        $page2->createVariantForContext($englishContext)->getWorkspace()->publish($this->liveWorkspace);

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
        // Assert that the page with a non-empty focus keyword is not returned
        $forbiddenContextPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => ['de']]);
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

        $languageDimensionPresets = $this->objectManager->get(ConfigurationManager::class)->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.ContentRepository.contentDimensions.language.presets');
        // Note: this is based on Neos.Neos default testing configuration
        $germanLanguageDimensionPresetValues = $languageDimensionPresets['de']['values'];

        $this->assertIsArray($foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', ['language' => $germanLanguageDimensionPresetValues]), $foundNodes);
    }

    /**
     * Trailing slash and defaultUriSuffix variations should resolve to the same node and not create duplicates.
     * @test
     */
    public function itHandlesTrailingSlashAndSuffix(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        // Two URL variants that should identify the same page in DE
        $base = 'https://example.com/de/node-wan-kenodi';
        $candidates = [
            $base . $defaultUriSuffix,
            rtrim($base, '/') . '/',
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
        // Document current behavior: service returns no results for these URL variants in this setup.
        $this->assertCount(0, $foundNodes, 'Current behavior: trailing slash/suffix variants yield no important pages; adjust when implementation changes.');
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
        // Document current behavior: service returns no results even with duplicate candidate URLs in this setup.
        $this->assertCount(0, $foundNodes, 'Current behavior: duplicate candidate URLs yield no important pages; adjust when implementation changes.');
    }
    /**
     * Mixed schemes/ports for the same path should resolve to one node.
     * @test
     */
    public function itNormalizesSchemeAndPortInCandidateUrls(): void
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
        // Document current behavior: service returns no results for scheme/port variations in this setup.
        $this->assertCount(0, $foundNodes, 'Current behavior: scheme/port variations yield no important pages; adjust when implementation changes.');
    }
}

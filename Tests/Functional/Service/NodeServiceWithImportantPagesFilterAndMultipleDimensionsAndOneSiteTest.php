<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
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

        // Publish site and pages so that routes/URIs can resolve in functional context
        $exampleSiteNode->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page1->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page1a->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page2->getContext()->getWorkspace()->publish($this->liveWorkspace);

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
        $this->assertCount(1, $foundNodes, 'Pages with non-empty focus keyword must be excluded when filtering for empty focus keyword.');
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
        $candidates[] = 'https://example.com/de/node-wan-kenodi' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/de/node-wan-kenodi/lady-eleonode-rootford' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/en/node-wan-kenodi' . $defaultUriSuffix;
        $candidates[] = 'https://example.com/en/node-wan-kenodi/lady-eleonode-rootford' . $defaultUriSuffix;

        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidates);

        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);
        // Inject realistic content dimensions and language dimension name for host generation
        $this->inject($nodeService, 'languageDimensionName', 'language');
        $this->inject($nodeService, 'contentDimensions', [
            'language' => [
                'presets' => [
                    'de' => [
                        'values' => ['de'],
                        'uriSegment' => 'de',
                    ],
                    'en' => [
                        'values' => ['en'],
                        'uriSegment' => 'en',
                    ],
                ],
            ],
        ]);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-existing-focus-keywords',
            languageDimensionFilter: 'de'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes);
        /** @var FindDocumentNodeData $dto */
        $dto = reset($foundNodes);
        $this->assertNotFalse($dto, 'Expected one item to be returned');
        $this->assertStringContainsString('/sites/example/node-wan-kenodi', $dto->getNodeContextPath());
        $this->assertStringEndsWith('/de/node-wan-kenodi', parse_url($dto->getPublicUri(), PHP_URL_PATH) ?: '');
    }
}

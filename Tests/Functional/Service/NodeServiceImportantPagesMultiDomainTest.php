<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeFindingService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceImportantPagesMultiDomainTest extends FunctionalTestCase
{
    protected array $dimensions = ['de'];
    protected array $siteHosts = ['example.com', 'example2.com'];

    public function setUp(): void
    {
        parent::setUp();
        // Create simple content on both sites and publish, so routing/URIs work in functional context
        $site1 = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($site1, 'site1-page', 'Site1', ['image1.jpg']);

        $site2 = $this->rootNode->getNode('/sites/example2');
        $page2 = $this->createPageWithImageNodes($site2, 'site2-page', 'Site2', ['image1.jpg']);

        // Publish to live because Important Pages work with public URIs
        $site1->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page1->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $site2->getContext()->getWorkspace()->publish($this->liveWorkspace);
        $page2->getContext()->getWorkspace()->publish($this->liveWorkspace);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * Important pages should be filtered to the current ControllerContext domain.
     * @test
     */
    public function itFiltersImportantPagesByCurrentDomain(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);
        $defaultUriSuffix = $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';

        // Return candidates from both hosts
        $candidates = [
            'https://example.com/de/site1-page' . $defaultUriSuffix,
            'https://example2.com/de/site2-page' . $defaultUriSuffix,
        ];
        $apiFacadeMock
            ->expects($this->once())
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->with($this->callback(function(array $hosts) {
                return $hosts === ['https://example.com/de'];
            }))
            ->willReturn($candidates);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $filter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-empty-focus-keywords',
            languageDimensionFilter: 'de'
        );

        // Ask for example.com context; expect only that site's page is returned
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($filter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Expected only current-domain nodes to be returned');
        $contextPaths = array_keys($foundNodes);
        $this->assertCount(1, $contextPaths);
        $this->assertStringStartsWith('/sites/example/site1-page@', $contextPaths[0], 'Expected node from the /sites/example tree only');
    }
}

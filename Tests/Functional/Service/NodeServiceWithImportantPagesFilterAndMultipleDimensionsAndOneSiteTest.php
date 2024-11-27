<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeFindingService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Service\SiteService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\MockObject\MockObject;

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

        // todo hack
        $page1->getContext()->getWorkspace()->publish($this->liveWorkspace);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function itFindsImportantPagesWithEmptyFocusKeyword(): void
    {
        // TODO maybe not working because nodes are not published to live???
        // that was true
        // now the route cannot be generated
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn([
//                'https://example.com/',
                'https://example.com/de/node-wan-kenodi.html',
            ]);
        /** @var NodeService|MockObject $nodeService */
        $nodeService = new NodeService(
            $this->workspaceRepository,
            $this->objectManager->get(FindDocumentNodeDataFactory::class),
            $this->objectManager->get(NodeTypeManager::class),
            $this->objectManager->get(SiteService::class),
            $apiFacadeMock,
            $this->objectManager->get(NodeFindingService::class),
        );
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);
        $this->inject($nodeService, 'contentDimensions', []);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            'important-pages',
            $this->currentUserWorkspace,
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
    }
}

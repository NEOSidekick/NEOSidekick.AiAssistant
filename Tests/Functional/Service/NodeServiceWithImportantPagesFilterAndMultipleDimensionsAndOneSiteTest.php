<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
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

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function itFindsImportantPagesWithEmptyFocusKeyword(): void
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        /** @var NodeService|MockObject $nodeService */
        $nodeService = $this->getAccessibleMock(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);


        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            'important-pages',
            $this->currentUserWorkspace,
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
    }
}

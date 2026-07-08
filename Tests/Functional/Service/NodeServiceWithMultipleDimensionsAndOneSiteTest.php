<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceWithMultipleDimensionsAndOneSiteTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $exampleSiteNode = $this->getNodeByPath('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg', 'image2.jpg']);
        $this->setNodeProperties($page1, ['focusKeyword' => 'some-value']);
        $this->createPageWithImageNodes($page1, 'lady-eleonode-rootford', 'Unterseite 1', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg', 'image2.jpg']);
    }

    protected function setUpContentInUserWorkspace(): void
    {
        // English variants exist only in the user workspace (as on Neos 8: created via a
        // user-workspace context and never published).
        foreach (['/sites/example', '/sites/example/node-wan-kenodi', '/sites/example/node-wan-kenodi/lady-eleonode-rootford', '/sites/example/node-mc-nodeface'] as $path) {
            $this->createLanguageVariant($this->getNodeByPath($path, $this->currentUserWorkspace), self::LANGUAGE_EN, $this->currentUserWorkspace);
        }
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPages(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        $this->assertCount(8, $foundNodes);
    }

    /**
     * @test
     */
    public function itDoesNotFindHiddenPages(): void
    {
        $nodeToBeHidden = $this->getNodeByPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace);
        $this->disableNode($nodeToBeHidden, $this->currentUserWorkspace);

        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        // the child is disabled through the inherited subtree tag
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        // the English variants (independent dimension value) stay visible
        $this->assertCount(6, $foundNodes);
    }

    /**
     * @test
     */
    public function itDoesNotFindRemovedPages(): void
    {
        $nodeToBeRemoved = $this->getNodeByPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace);
        $this->removeNode($nodeToBeRemoved, $this->currentUserWorkspace);

        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        // removing the de variant leaves the independent English variants in place
        $this->assertCount(6, $foundNodes);
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPagesInGermanOnly(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: self::LANGUAGE_DE);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        $this->assertCount(4, $foundNodes);
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPagesWithoutFocusKeywordOnly(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, focusKeywordPropertyFilter: 'only-empty-focus-keywords');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        $this->assertCount(6, $foundNodes);
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPagesWithFocusKeywordOnly(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, focusKeywordPropertyFilter: 'only-existing-focus-keywords');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayNotHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        $this->assertCount(2, $foundNodes);
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPagesMatchingNodeTypeFilter(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, nodeTypeFilter: 'NEOSidekick.AiAssistant.Testing:HomePage');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertCount(2, $foundNodes);
    }

    /**
     * @test
     */
    public function itThrowsExceptionIfWorkspaceDoesNotExist(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'non-existing-workspace');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1713440899886);

        $nodeService->find($findDocumentNodesFilter, $controllerContext);
    }

    /**
     * @test
     */
    public function itFindsVisiblePagesInEnglishOnly(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: self::LANGUAGE_EN);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace, self::LANGUAGE_EN), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, self::LANGUAGE_EN), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, self::LANGUAGE_EN), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, self::LANGUAGE_EN), $foundNodes);
        $this->assertCount(4, $foundNodes);
    }
}

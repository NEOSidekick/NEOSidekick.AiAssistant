<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceWithMultipleDimensionsAndOneSiteTest extends FunctionalTestCase
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
    public function itFindsVisibleNestedPages(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertCount(8, $foundNodes);
    }

    /**
     * @test
     */
    public function itDoesNotFindHiddenPages(): void
    {
        $nodeToBeHidden = $this->rootNode->getNode('/sites/example/node-wan-kenodi');
        $nodeToBeHidden->setHidden(true);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertCount(6, $foundNodes);
    }

    /**
     * @test
     */
    public function itDoesNotFindRemovedPages(): void
    {
        $nodeToBeHidden = $this->rootNode->getNode('/sites/example/node-wan-kenodi');
        $nodeToBeHidden->remove();

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertCount(6, $foundNodes);
    }

    /**
     * @test
     */
    public function itFindsVisibleNestedPagesInGermanOnly(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: 'de');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
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

        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertCount(2, $foundNodes);
    }

    /**
     * @test
     */
    public function itWillThrowsExceptionIfWorkspaceDoesNotExist(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'non-existing-workspace');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1713440899886);

        $nodeService->find($findDocumentNodesFilter, $controllerContext);
    }

//    public function setUpDataProviderTest(): void {
//        $nodes = [];
//        foreach (['live', $this->currentGroupWorkspace, $this->currentUserWorkspace] as $workspaceName) {
//            $nodes[] = new Node('node1', $workspaceName);
//        }
//
//        foreach ($nodes as $workspaceNode) {
//            $workspaceNode->setPropertoy('focusKeyword', '');
//            $node = $workspaceNode->clone();
//            $node->setPropertoy('focusKeyword', 'some value');
//            $nodes[] = $node;
//        }
//
//        foreach ($nodes as $workspaceNodeWithFocusKeywordSet) {
//            $workspaceNodeWithFocusKeywordSet->setProperty('titleOverride', '');
//            $node = $workspaceNode->clone();
//            $node->setPropertoy('titleOverride', 'some value');
//            $nodes[] = $node;
//        }
//
//        foreach ($nodes as $workspaceNodeWithFocusKeywordSet) {
//            $workspaceNodeWithFocusKeywordSet->setProperty('metaDescription', '');
//            $node = $workspaceNode->clone();
//            $node->setPropertoy('titleOverride', 'some value');
//            $nodes[] = $node;
//        }
//
//    }

//    public function findDocumentNodeFilterCombinationsDataProvider(): array
//    {
//        $testSets = [];
//        foreach (['live', $this->currentGroupWorkspace, $this->currentUserWorkspace] as $workspaceName) {
//            $expected = match($workspaceName) {
//                'live' => 1,
//                $this->currentGroupWorkspace => 2,
//                $this->currentUserWorkspace => 3,
//            };
//            foreach ([null, 'only-empty-focus-keywords', 'only-existing-focus-keywords'] as $focusKeywordPropertyFilter) {
//                $expected *= match($focusKeywordPropertyFilter) {
//                    null => 2,
//                    'only-empty-focus-keywords' => 1,
//                    'only-existing-focus-keywords' => 1,
//                };
//                foreach ([null, 'only-empty-focus-keywords', 'only-existing-focus-keywords'] as $focusKeywordPropertyFilter) {
//                    $expected *= match($focusKeywordPropertyFilter) {
//                        null => 2,
//                        'only-empty-focus-keywords' => 1,
//                        'only-existing-focus-keywords' => 1,
//                    };
//                foreach ([...$this->dimensions, implode(',', $this->dimensions)] as $languageDimensionFilter) {
//                    foreach ([null, 'NEOSidekick.AiAssistant.Testing:HomePage'] as $nodeTypeFilter) {
//                        $testSets[] = [
//                            'findDocumentNodeFilter' => new FindDocumentNodesFilter(
//                                filter: 'custom',
//                                workspace: $workspaceName,
//                                seoPropertiesFilter: null,
//                                focusKeywordPropertyFilter: $focusKeywordPropertyFilter,
//                                languageDimensionFilter: $languageDimensionFilter,
//                                nodeTypeFilter: $nodeTypeFilter
//                            ),
//                            'expectedCount' => 2
//
//                        ];
//                    }
//                }
//            }
//        }
//        return $testSets;
//    }
}

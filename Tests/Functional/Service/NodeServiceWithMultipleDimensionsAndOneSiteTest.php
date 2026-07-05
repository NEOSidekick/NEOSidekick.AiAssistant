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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertCount(6, $foundNodes);
    }

    /**
     * The backend module frontend always sends nodeTypeFilter and baseNodeTypeFilter,
     * as empty strings when unset. Empty strings must behave like "not set" (fall back
     * to the default document/base node types) instead of producing an empty node type
     * intersection and therefore zero results.
     * @test
     */
    public function itTreatsEmptyStringNodeTypeFiltersAsUnset(): void
    {
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'custom',
            workspace: $this->currentUserWorkspace,
            nodeTypeFilter: '',
            baseNodeTypeFilter: ''
        );
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertCount(8, $foundNodes);
    }

    /**
     * Pages hidden via timed visibility (hiddenAfterDateTime in the past) must be
     * excluded just like pages with the hidden flag — including their subpages.
     * @test
     */
    public function itDoesNotFindTimedHiddenPages(): void
    {
        $nodeToBeHidden = $this->rootNode->getNode('/sites/example/node-wan-kenodi');
        $nodeToBeHidden->setHiddenAfterDateTime(new \DateTime('-1 day'));

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]), $foundNodes);
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
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: 'en');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['en']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, ['language' => ['en']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi/lady-eleonode-rootford', $this->currentUserWorkspace, ['language' => ['en']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, ['language' => ['en']]), $foundNodes);
        $this->assertCount(4, $foundNodes);
    }
}

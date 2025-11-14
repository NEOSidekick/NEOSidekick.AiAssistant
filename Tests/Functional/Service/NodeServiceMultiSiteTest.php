<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceMultiSiteTest extends FunctionalTestCase
{
    protected array $dimensions = ['de', 'en'];
    protected array $siteHosts = ['example.com', 'example2.com'];

    public function setUp(): void
    {
        parent::setUp();

        // Create content for example.com
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'site1-page-a', 'Site1 Page A', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($page1, 'site1-sub-a', 'Site1 Sub A', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'site1-page-b', 'Site1 Page B', ['image1.jpg', 'image2.jpg']);

        // Create content for example2.com
        $example2SiteNode = $this->rootNode->getNode('/sites/example2');
        $page1b = $this->createPageWithImageNodes($example2SiteNode, 'node-two-wan-kenodi', 'Seite 1 (Site 2)', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($page1b, 'lady-eleonode-rootford-2', 'Unterseite 1 (Site 2)', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($example2SiteNode, 'node-two-mc-nodeface', 'Seite 2 (Site 2)', ['image1.jpg', 'image2.jpg']);

        // Create EN variants for both sites
        $englishContext = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => ['language' => ['en']]
        ]);

        $exampleSiteNode->createVariantForContext($englishContext);
        $exampleSiteNode->getNode('site1-page-a')->createVariantForContext($englishContext);
        $exampleSiteNode->getNode('site1-page-a/site1-sub-a')->createVariantForContext($englishContext);
        $exampleSiteNode->getNode('site1-page-b')->createVariantForContext($englishContext);

        $example2SiteNode->createVariantForContext($englishContext);
        $example2SiteNode->getNode('node-two-wan-kenodi')->createVariantForContext($englishContext);
        $example2SiteNode->getNode('node-two-wan-kenodi/lady-eleonode-rootford-2')->createVariantForContext($englishContext);
        $example2SiteNode->getNode('node-two-mc-nodeface')->createVariantForContext($englishContext);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function itFindsNodesFromCurrentSiteAcrossMultipleDomains(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        // Ensure nodes from example.com are present
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/site1-page-a', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/site1-page-a/site1-sub-a', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/site1-page-b', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);

        // Note: We deliberately do not assert exclusion or inclusion of other sites here,
        // as the current service behavior regarding domain scoping is not yet finalized.
    }

    /**
     * @test
     */
    public function itFindsNodesForTheControllerContextDomain(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example2.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        // Assert nodes from /sites/example2 (current domain) are present
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example2', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example2/node-two-wan-kenodi', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example2/node-two-wan-kenodi/lady-eleonode-rootford-2', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example2/node-two-mc-nodeface', $this->currentUserWorkspace, ['language' => ['de']]), $foundNodes);

        // We do not assert counts or cross-site inclusion/exclusion to avoid cementing undefined behavior.
    }
}

<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceMultiSiteTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com', 'example2.com'];

    protected function setUpContentInLive(): void
    {
        // Create content for example.com
        $exampleSiteNode = $this->getNodeByPath('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'site1-page-a', 'Site1 Page A', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($page1, 'site1-sub-a', 'Site1 Sub A', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'site1-page-b', 'Site1 Page B', ['image1.jpg', 'image2.jpg']);

        // Create content for example2.com
        $example2SiteNode = $this->getNodeByPath('/sites/example2');
        $page1b = $this->createPageWithImageNodes($example2SiteNode, 'node-two-wan-kenodi', 'Seite 1 (Site 2)', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($page1b, 'lady-eleonode-rootford-2', 'Unterseite 1 (Site 2)', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($example2SiteNode, 'node-two-mc-nodeface', 'Seite 2 (Site 2)', ['image1.jpg', 'image2.jpg']);

    }

    protected function setUpContentInUserWorkspace(): void
    {
        // English variants for both sites, in the user workspace (as on Neos 8)
        foreach (['/sites/example', '/sites/example/site1-page-a', '/sites/example/site1-page-a/site1-sub-a', '/sites/example/site1-page-b',
                  '/sites/example2', '/sites/example2/node-two-wan-kenodi', '/sites/example2/node-two-wan-kenodi/lady-eleonode-rootford-2', '/sites/example2/node-two-mc-nodeface'] as $path) {
            $this->createLanguageVariant($this->getNodeByPath($path, $this->currentUserWorkspace), $this->secondaryLanguage(), $this->currentUserWorkspace);
        }
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
        $this->assertArrayHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/site1-page-a', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/site1-page-a/site1-sub-a', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example/site1-page-b', $this->currentUserWorkspace), $foundNodes);
        // ...and nodes from example2.com are not
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example2', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example2/node-two-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example2/node-two-wan-kenodi/lady-eleonode-rootford-2', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example2/node-two-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
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
        $this->assertArrayHasKey($this->addressForPath('/sites/example2', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example2/node-two-wan-kenodi', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example2/node-two-wan-kenodi/lady-eleonode-rootford-2', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayHasKey($this->addressForPath('/sites/example2/node-two-mc-nodeface', $this->currentUserWorkspace), $foundNodes);
        // ...and nodes from /sites/example are not
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/site1-page-a', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/site1-page-a/site1-sub-a', $this->currentUserWorkspace), $foundNodes);
        $this->assertArrayNotHasKey($this->addressForPath('/sites/example/site1-page-b', $this->currentUserWorkspace), $foundNodes);
    }
}

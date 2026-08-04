<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Documents how {@see NodeService::find()} interacts with workspaces and disabling.
 *
 * SEMANTIC CHANGE vs. Neos 8: the old NodeData-based find() could still return a node in a
 * user workspace although it was hidden there (the SQL filter only saw the visible live row).
 * The Neos 9 content graph applies the "disabled" subtree tag of the queried workspace, so a
 * node disabled in the user workspace is now correctly excluded from user-workspace results
 * while remaining visible in live until the change is published — and excluded from live
 * after publishing. Both assertions below were inverted accordingly (they documented the
 * old behavior as a known quirk, see the git history of this file).
 */
class NodeServiceWorkspacesTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $exampleSiteNode = $this->getNodeByPath('/sites/example');
        $this->createPageWithImageNodes($exampleSiteNode, 'workspace-test', 'Workspace Test', ['image1.jpg']);
    }

    /**
     * Ensure user workspace changes do not leak to live until published.
     * @test
     */
    public function itFindsNodesInUserWorkspace(): void
    {
        $userWsNode = $this->getNodeByPath('/sites/example/workspace-test', $this->currentUserWorkspace);
        $this->assertNotNull($userWsNode, 'Precondition: node must exist in user workspace');
        $this->disableNode($userWsNode, $this->currentUserWorkspace);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');

        // In LIVE workspace the page is still visible (not yet published)
        $liveFilter = new FindDocumentNodesFilter('custom', 'live');
        $liveFound = $nodeService->find($liveFilter, $controllerContext);
        $this->assertArrayHasKey(
            $this->addressForPath('/sites/example/workspace-test', 'live'),
            $liveFound,
            'Node should still be visible in live before publishing'
        );

        // In the user workspace the disabled subtree tag applies: the node is excluded
        // (addressForPath still resolves the node — the test base's subgraph shows disabled nodes)
        $userFilter = new FindDocumentNodesFilter('custom', $this->currentUserWorkspace);
        $userFound = $nodeService->find($userFilter, $controllerContext);
        $this->assertArrayNotHasKey(
            $this->addressForPath('/sites/example/workspace-test', $this->currentUserWorkspace),
            $userFound,
            'Neos 9: a node disabled in the user workspace is excluded from user-workspace results'
        );
    }

    /**
     * After publishing the disable from user to live, live must exclude the node too.
     * (Neos 8 behaved differently here — see class docblock.)
     * @test
     */
    public function itReflectsPublishingStateChanges(): void
    {
        $userWsNode = $this->getNodeByPath('/sites/example/workspace-test', $this->currentUserWorkspace);
        $this->disableNode($userWsNode, $this->currentUserWorkspace);

        $controllerContext = $this->createControllerContextForDomain('example.com');
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $liveFilter = new FindDocumentNodesFilter('custom', 'live');

        // Before publishing, live still sees it
        $liveKey = $this->addressForPath('/sites/example/workspace-test', 'live');
        $liveFoundBefore = $nodeService->find($liveFilter, $controllerContext);
        $this->assertArrayHasKey($liveKey, $liveFoundBefore, 'Sanity check: visible in live prior to publishing user change');

        $this->publishUserWorkspaceToLive();

        $liveFoundAfter = $nodeService->find($liveFilter, $controllerContext);
        $this->assertArrayNotHasKey($liveKey, $liveFoundAfter, 'After publishing the disable, live must exclude the node');
    }
}

<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\Patch\CreatedNodeInfo;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * `createNode` must produce the node the caller asked for plus the node type's tethered
 * `childNodes:` — and nothing else.
 *
 * Flowpack.NodeTemplates is a creation-*dialog* handler: its `handle()` takes
 * "incoming data from the creationDialog". This API has no dialog and passes an empty array, so
 * `${data.*}` evaluates to null and unconditional template children are created unasked. Both
 * effects are asserted here against the database, not the node registry.
 */
class NodePatchServiceNodeTemplatesTest extends FunctionalTestCase
{
    private const PAGE_PATH = '/sites/example/patch-test';
    private const MAIN_PATH = self::PAGE_PATH . '/main';
    private const PAGE_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const TEMPLATED_TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:TemplatedText';
    private const TEMPLATED_CONTAINER_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:TemplatedContainer';

    protected array $dimensions = ['de'];
    protected array $siteHosts = ['example.com'];

    private string $mainIdentifier;
    private string $siteIdentifier;

    public function setUp(): void
    {
        parent::setUp();

        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page = $exampleSiteNode->createNode('patch-test', $this->nodeTypeManager->getNodeType(self::PAGE_NODE_TYPE));
        $page->setProperty('title', 'Patch Test');

        $this->mainIdentifier = $page->getNode('main')->getIdentifier();
        $this->siteIdentifier = $exampleSiteNode->getIdentifier();

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $this->assertSame(0, $this->countRowsBelow(self::MAIN_PATH), 'Precondition: main is empty');
    }

    /**
     * The regression guard for the data loss: a root `options.template.properties` entry reading
     * `${data.text}` must not overwrite the value the caller supplied in the same patch.
     *
     * @test
     */
    public function callerPropertiesSurviveNodeTypeTemplates(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->mainIdentifier,
                'nodeType' => self::TEMPLATED_TEXT_NODE_TYPE,
                'position' => 'into',
                'properties' => ['text' => 'caller value'],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));
        $this->persistenceManager->persistAll();

        $properties = $this->fetchPropertiesBlobOfIdentifier($result->getResults()[0]['nodeId']);
        $this->assertStringContainsString(
            'caller value',
            $properties,
            'The caller supplied "text"; the node type template must not null it. Stored: ' . $properties
        );
    }

    /**
     * An unconditional template child must not be created: the caller asked for one node.
     *
     * @test
     */
    public function createDoesNotAddNodeTemplateChildren(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->mainIdentifier,
                'nodeType' => self::TEMPLATED_CONTAINER_NODE_TYPE,
                'position' => 'into',
                'properties' => [],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));
        $this->persistenceManager->persistAll();

        $containerPath = $this->fetchPathOfIdentifier($result->getResults()[0]['nodeId']);
        $this->assertSame(0, $this->countRowsBelow($containerPath), 'No template child may be created');
        $this->assertCount(
            1,
            $result->getResults()[0]['createdNodes'],
            'Only the requested node is reported as created'
        );
    }

    /**
     * The counterpart: tethered `childNodes:` are part of the node type, are still created and are
     * still reported in `createdNodes` at depth 1. Guards the fix against over-deletion (the `$ref/main`
     * anchor itself is covered by NodePatchServiceRefTest).
     *
     * @test
     */
    public function tetheredChildNodesAreStillCreatedAndReported(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->siteIdentifier,
                'nodeType' => self::PAGE_NODE_TYPE,
                'position' => 'into',
                'properties' => ['title' => 'Second Page'],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));

        /** @var CreatedNodeInfo[] $createdNodes */
        $createdNodes = $result->getResults()[0]['createdNodes'];
        $byDepthAndName = array_map(
            static fn(CreatedNodeInfo $node): string => $node->getDepth() . ':' . $node->getNodeName(),
            $createdNodes
        );
        $this->assertSame(self::PAGE_NODE_TYPE, $createdNodes[0]->getNodeType());
        $this->assertSame(0, $createdNodes[0]->getDepth());
        $this->assertContains('1:main', $byDepthAndName, 'The tethered main collection is reported at depth 1');
    }

    private function applyPatches(array $patches): PatchResult
    {
        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            ['language' => ['de']]
        );
    }

    private function countRowsBelow(string $parentPath): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE parentpath = ? AND removed = 0',
            [$parentPath]
        );
    }

    private function fetchPathOfIdentifier(string $identifier): string
    {
        return (string)$this->connection()->fetchOne(
            'SELECT path FROM neos_contentrepository_domain_model_nodedata WHERE identifier = ? AND removed = 0',
            [$identifier]
        );
    }

    private function fetchPropertiesBlobOfIdentifier(string $identifier): string
    {
        return (string)$this->connection()->fetchOne(
            'SELECT properties FROM neos_contentrepository_domain_model_nodedata WHERE identifier = ? AND removed = 0',
            [$identifier]
        );
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->objectManager->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
    }
}

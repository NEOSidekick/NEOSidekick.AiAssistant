<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Doctrine\ORM\EntityManagerInterface;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Batch-local references (`ref` on createNode, `$<ref>` and `$<ref>/<childName>` anchors) where the
 * database is the witness: what lands in which parent, in which order, and that a failing ref batch
 * leaves nothing behind.
 *
 * Row counts and sibling order are read via SQL, not through the content context, because the context
 * answers from the in-memory node registry first.
 */
class NodePatchServiceRefTest extends FunctionalTestCase
{
    private const PAGE_PATH = '/sites/example/patch-test';
    private const MAIN_PATH = self::PAGE_PATH . '/main';
    private const PAGE_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const CONTAINER_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Container';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const FORBIDDEN_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:ForbiddenContent';

    protected array $dimensions = ['de'];
    protected array $siteHosts = ['example.com'];

    private string $pageIdentifier;
    private string $mainIdentifier;
    private string $existingTextIdentifier;

    public function setUp(): void
    {
        parent::setUp();

        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page = $exampleSiteNode->createNode('patch-test', $this->nodeTypeManager->getNodeType(self::PAGE_NODE_TYPE));
        $page->setProperty('title', 'Patch Test');
        $main = $page->getNode('main');
        $existingText = $main->createNode('text-existing', $this->nodeTypeManager->getNodeType(self::TEXT_NODE_TYPE));
        $existingText->setProperty('text', 'existing');

        $this->pageIdentifier = $page->getIdentifier();
        $this->mainIdentifier = $main->getIdentifier();
        $this->existingTextIdentifier = $existingText->getIdentifier();

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $this->assertSame(1, $this->countRowsBelow(self::MAIN_PATH), 'Precondition: only the existing text lives in main');
    }

    /**
     * Plain ref: children created `into "$container"` land under the container, in patch order.
     *
     * @test
     */
    public function createIntoPlainRefAppendsChildrenInPatchOrder(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->mainIdentifier, self::CONTAINER_NODE_TYPE, 'into', [], 'container'),
            $this->createTextPatch('$container', 'into', 'one'),
            $this->createTextPatch('$container', 'into', 'two'),
            $this->createTextPatch('$container', 'into', 'three'),
        ]);

        $this->assertSucceeded($result, 4);
        $this->persistenceManager->persistAll();

        $rows = $result->getResults();
        $this->assertArrayNotHasKey('ref', $rows[0], 'Success rows do not echo the ref');
        $containerPath = $this->fetchPathOfIdentifier($rows[0]['nodeId']);
        $this->assertSame(self::MAIN_PATH, dirname($containerPath));
        $this->assertSame(2, $this->countRowsBelow(self::MAIN_PATH));
        $this->assertSame(
            [$rows[1]['nodeId'], $rows[2]['nodeId'], $rows[3]['nodeId']],
            $this->fetchIdentifiersBelowInSortingOrder($containerPath)
        );
    }

    /**
     * Path form: a text created `into "$page/main"` lands in the auto-created `main` of the page created
     * earlier in the same batch.
     *
     * @test
     */
    public function createIntoAutoCreatedChildOfRefLandsInThatChild(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->pageIdentifier, self::PAGE_NODE_TYPE, 'into', ['title' => 'Sub'], 'page'),
            $this->createTextPatch('$page/main', 'into', 'in sub main'),
        ]);

        $this->assertSucceeded($result, 2);
        $this->persistenceManager->persistAll();

        $rows = $result->getResults();
        $subPagePath = $this->fetchPathOfIdentifier($rows[0]['nodeId']);
        $this->assertSame(self::PAGE_PATH, dirname($subPagePath));
        $this->assertSame([$rows[1]['nodeId']], $this->fetchIdentifiersBelowInSortingOrder($subPagePath . '/main'));
        $this->assertSame(self::TEXT_NODE_TYPE, $this->fetchNodeTypeOfIdentifier($rows[1]['nodeId']));
        $this->assertSame(1, $this->countRowsBelow(self::MAIN_PATH), 'The test page\'s own main is untouched');
    }

    /**
     * An `after` chain anchored on the previous patch's `$ref` keeps patch order behind the stored anchor.
     *
     * @test
     */
    public function afterChainOnPreviousRefKeepsPatchOrder(): void
    {
        $result = $this->applyPatches([
            $this->createTextPatch($this->existingTextIdentifier, 'after', 'a', 'a'),
            $this->createTextPatch('$a', 'after', 'b', 'b'),
            $this->createTextPatch('$b', 'after', 'c', 'c'),
        ]);

        $this->assertSucceeded($result, 3);
        $this->persistenceManager->persistAll();

        $rows = $result->getResults();
        $this->assertSame(
            [$this->existingTextIdentifier, $rows[0]['nodeId'], $rows[1]['nodeId'], $rows[2]['nodeId']],
            $this->fetchIdentifiersBelowInSortingOrder(self::MAIN_PATH)
        );
    }

    /**
     * An execution failure after ref'd creates rolls everything back: the container, its children and the
     * nodes flushed by the `after` move leave no rows, not even after the end-of-request persist.
     *
     * The failing patch is the stored-anchor injection of the rollback tests (a forbidden type into the
     * stored `main`, which the validator checks against the parent type only).
     *
     * @test
     */
    public function executionFailureAfterRefCreatesLeavesNoRows(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->mainIdentifier, self::CONTAINER_NODE_TYPE, 'into', [], 'container'),
            $this->createTextPatch('$container', 'into', 'one'),
            $this->createTextPatch($this->existingTextIdentifier, 'after', 'a', 'a'),
            $this->createTextPatch('$a', 'after', 'b'),
            $this->createPatch($this->mainIdentifier, self::FORBIDDEN_NODE_TYPE, 'into'),
        ]);

        $this->assertFalse($result->isSuccess(), 'Batch should fail: ' . json_encode($result));
        $this->assertTrue($result->isRollbackPerformed());
        $this->assertSame(4, $result->getError()->getPatchIndex());
        $this->assertStringContainsString('Cannot create new node', $result->getError()->getMessage());
        $this->assertSame($this->mainIdentifier, $result->getError()->getNodeId());
        $this->assertNull($result->getError()->getRef());

        $this->persistenceManager->persistAll();

        $this->assertSame([$this->existingTextIdentifier], $this->fetchIdentifiersBelowInSortingOrder(self::MAIN_PATH));
        $this->assertSame(0, $this->countRowsOfNodeType(self::CONTAINER_NODE_TYPE));
        $this->assertSame(0, $this->countRowsOfNodeType(self::FORBIDDEN_NODE_TYPE));
        $this->assertSame(
            1,
            $this->countRowsOfNodeType(self::TEXT_NODE_TYPE),
            'Only the text from the fixture survives; the texts created into "$container" and by the "after" chain are gone'
        );
    }

    /**
     * A ref batch refused by the validator (forbidden type into `$page/main`, caught through the page type's
     * grandchild constraints) is never attempted: no transaction, no rows, `error.ref` carries the anchor.
     *
     * @test
     */
    public function validationRefusalOfRefBatchLeavesNoRows(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->pageIdentifier, self::PAGE_NODE_TYPE, 'into', ['title' => 'Sub'], 'page'),
            $this->createTextPatch('$page/main', 'into', 'fine'),
            $this->createPatch('$page/main', self::FORBIDDEN_NODE_TYPE, 'into'),
        ]);

        $this->assertFalse($result->isSuccess(), 'Batch should be refused: ' . json_encode($result));
        $this->assertFalse($result->isRollbackPerformed());
        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $json['error']['patchIndex']);
        $this->assertSame('createNode', $json['error']['operation']);
        $this->assertNull($json['error']['nodeId']);
        $this->assertSame('$page/main', $json['error']['ref']);
        $this->assertStringContainsString('is not allowed as child of "$page/main"', $json['error']['message']);
        $this->assertStringContainsString('"' . self::TEXT_NODE_TYPE . '"', $json['error']['message']);

        $this->persistenceManager->persistAll();

        $this->assertSame(1, $this->countRowsBelow(self::PAGE_PATH), 'Only the page\'s own main remains below it');
        $this->assertSame(1, $this->countRowsBelow(self::MAIN_PATH));
        $this->assertSame(1, $this->countRowsOfNodeType(self::PAGE_NODE_TYPE));
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     */
    private function applyPatches(array $patches): PatchResult
    {
        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            ['language' => ['de']]
        );
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function createPatch(string $positionRelativeToNodeId, string $nodeType, string $position, array $properties = [], ?string $ref = null): array
    {
        $patch = [
            'operation' => 'createNode',
            'positionRelativeToNodeId' => $positionRelativeToNodeId,
            'nodeType' => $nodeType,
            'position' => $position,
            'properties' => $properties,
        ];
        if ($ref !== null) {
            $patch['ref'] = $ref;
        }

        return $patch;
    }

    /**
     * @return array<string, mixed>
     */
    private function createTextPatch(string $positionRelativeToNodeId, string $position, string $text, ?string $ref = null): array
    {
        return $this->createPatch($positionRelativeToNodeId, self::TEXT_NODE_TYPE, $position, ['text' => $text], $ref);
    }

    private function assertSucceeded(PatchResult $result, int $expectedRows): void
    {
        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));
        $this->assertCount($expectedRows, $result->getResults());
    }

    private function countRowsBelow(string $parentPath): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE parentpath = ?',
            [$parentPath]
        );
    }

    private function countRowsOfNodeType(string $nodeType): int
    {
        return (int)$this->connection()->fetchOne(
            'SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE nodetype = ?',
            [$nodeType]
        );
    }

    private function fetchPathOfIdentifier(string $identifier): string
    {
        $path = $this->connection()->fetchOne(
            'SELECT path FROM neos_contentrepository_domain_model_nodedata WHERE identifier = ?',
            [$identifier]
        );
        $this->assertIsString($path, sprintf('Node "%s" should be stored', $identifier));

        return $path;
    }

    private function fetchNodeTypeOfIdentifier(string $identifier): string
    {
        return (string)$this->connection()->fetchOne(
            'SELECT nodetype FROM neos_contentrepository_domain_model_nodedata WHERE identifier = ?',
            [$identifier]
        );
    }

    /**
     * @return array<int, string>
     */
    private function fetchIdentifiersBelowInSortingOrder(string $parentPath): array
    {
        return $this->connection()->fetchFirstColumn(
            'SELECT identifier FROM neos_contentrepository_domain_model_nodedata WHERE parentpath = ? ORDER BY sortingindex ASC',
            [$parentPath]
        );
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        return $this->objectManager->get(EntityManagerInterface::class)->getConnection();
    }
}

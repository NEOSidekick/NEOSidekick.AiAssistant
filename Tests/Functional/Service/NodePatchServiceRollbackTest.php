<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Doctrine\ORM\EntityManagerInterface;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Proves that a rolled-back apply-patches batch writes nothing, even after Flow's end-of-request
 * persist (emulated here with {@see \Neos\Flow\Persistence\PersistenceManagerInterface::persistAll()}).
 *
 * Doctrine's `EntityManager::rollback()` only rolls back the connection; the unit of work keeps the
 * pending entities. Without discarding them after the rollback, the end-of-request persist leaks the
 * nodes of the successful patches (`into`) or fails with an OptimisticLockException on nodes that
 * were already flushed inside the transaction (`before`/`after`).
 *
 * Row counts are taken via SQL, not through the content context, because the context answers from the
 * in-memory node registry first.
 */
class NodePatchServiceRollbackTest extends FunctionalTestCase
{
    private const PAGE_PATH = '/sites/example/patch-test';
    private const MAIN_PATH = self::PAGE_PATH . '/main';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const FORBIDDEN_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:ForbiddenContent';

    protected array $dimensions = ['de'];
    protected array $siteHosts = ['example.com'];

    private string $mainIdentifier;
    private string $existingTextIdentifier;

    public function setUp(): void
    {
        parent::setUp();

        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page = $exampleSiteNode->createNode('patch-test', $this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:RestrictedPage'));
        $page->setProperty('title', 'Patch Test');
        $main = $page->getNode('main');
        $existingText = $main->createNode('text-existing', $this->nodeTypeManager->getNodeType(self::TEXT_NODE_TYPE));
        $existingText->setProperty('text', 'existing');

        $this->mainIdentifier = $main->getIdentifier();
        $this->existingTextIdentifier = $existingText->getIdentifier();

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $this->assertSame(1, $this->countRowsBelowMain(), 'Precondition: only the existing text lives in main');
    }

    /**
     * (a) Two `into` creates followed by a failing patch: the pending inserts of patches 0 and 1 must not be
     * written by the end-of-request persist.
     *
     * @test
     */
    public function failedBatchWithIntoCreatesLeavesNoRowsAfterPersistAll(): void
    {
        $result = $this->applyPatches([
            $this->createTextPatch($this->mainIdentifier, 'into', 'first'),
            $this->createTextPatch($this->mainIdentifier, 'into', 'second'),
            $this->createForbiddenPatch(),
        ]);

        $this->assertFailedAtIndex($result, 2);
        $this->persistenceManager->persistAll();

        $this->assertSame(1, $this->countRowsBelowMain());
        $this->assertSame(0, $this->countRowsOfNodeType(self::FORBIDDEN_NODE_TYPE));
    }

    /**
     * (b) An `after` create flushes inside the transaction. After the rollback the flushed node must be
     * discarded: the service returns the failure result either way, so the witness for the former HTTP 500
     * is that the `persistAll()` afterwards does not throw an OptimisticLockException.
     *
     * @test
     */
    public function failedBatchWithAfterCreateReturnsFailureResultAndLeavesNoRows(): void
    {
        $result = $this->applyPatches([
            $this->createTextPatch($this->existingTextIdentifier, 'after', 'after existing'),
            $this->createTextPatch($this->mainIdentifier, 'into', 'second'),
            $this->createForbiddenPatch(),
        ]);

        $this->assertFailedAtIndex($result, 2);
        $json = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($json['success']);
        $this->assertTrue($json['rollbackPerformed']);
        $this->assertSame(2, $json['error']['patchIndex']);

        $this->persistenceManager->persistAll();

        $this->assertSame(1, $this->countRowsBelowMain());
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
     * @return array<string, mixed>
     */
    private function createTextPatch(string $positionRelativeToNodeId, string $position, string $text): array
    {
        return [
            'operation' => 'createNode',
            'positionRelativeToNodeId' => $positionRelativeToNodeId,
            'nodeType' => self::TEXT_NODE_TYPE,
            'position' => $position,
            'properties' => ['text' => $text],
        ];
    }

    /**
     * Passes validation (ContentCollection allows `*`) and fails on execution, because the auto-created
     * `main` applies the page's grandchild constraints.
     *
     * @return array<string, mixed>
     */
    private function createForbiddenPatch(): array
    {
        return [
            'operation' => 'createNode',
            'positionRelativeToNodeId' => $this->mainIdentifier,
            'nodeType' => self::FORBIDDEN_NODE_TYPE,
            'position' => 'into',
        ];
    }

    private function assertFailedAtIndex(PatchResult $result, int $patchIndex): void
    {
        $this->assertFalse($result->isSuccess(), 'Batch should fail: ' . json_encode($result));
        $this->assertTrue($result->isRollbackPerformed());
        $this->assertNotNull($result->getError());
        $this->assertSame($patchIndex, $result->getError()->getPatchIndex());
        $this->assertSame('createNode', $result->getError()->getOperation());
        $this->assertStringContainsString('Cannot create new node', $result->getError()->getMessage());
    }

    private function countRowsBelowMain(): int
    {
        return (int)$this->objectManager->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE parentpath = ?',
            [self::MAIN_PATH]
        );
    }

    private function countRowsOfNodeType(string $nodeType): int
    {
        return (int)$this->objectManager->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM neos_contentrepository_domain_model_nodedata WHERE nodetype = ?',
            [$nodeType]
        );
    }
}

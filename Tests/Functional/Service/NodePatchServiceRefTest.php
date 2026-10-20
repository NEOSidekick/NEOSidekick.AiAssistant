<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Batch-local references (`ref` on createNode, `$<ref>` and `$<ref>/<childName>` anchors) where the
 * content repository is the witness: what lands in which parent, in which order, and that a ref batch
 * the validator refuses writes nothing.
 *
 * The Neos 9 content repository has no rollback, so a batch is only all-or-nothing up to validation.
 * That is why the validator applies the constraints the repository enforces, including those a
 * tethered child's owner declares for it.
 */
class NodePatchServiceRefTest extends FunctionalTestCase
{
    private const PAGE_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const CONTAINER_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Container';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const FORBIDDEN_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:ForbiddenContent';

    protected array $siteHosts = ['example.com'];

    private NodeAggregateId $pageId;
    private NodeAggregateId $mainId;
    private NodeAggregateId $existingTextId;

    protected function setUpContentInLive(): void
    {
        $site = $this->getNodeByPath('/sites/example');
        $this->assertNotNull($site);

        $this->pageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $this->pageId,
            NodeTypeName::fromString(self::PAGE_NODE_TYPE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $site->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Patch Test', 'uriPathSegment' => 'patch-test'])
        )->withNodeName(NodeName::fromString('patch-test')));

        $main = $this->subgraph()->findNodeByPath(NodeName::fromString('main'), $this->pageId);
        $this->assertNotNull($main, 'Tethered "main" child was not created');
        $this->mainId = $main->aggregateId;

        $this->existingTextId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $this->existingTextId,
            NodeTypeName::fromString(self::TEXT_NODE_TYPE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $this->mainId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['text' => 'existing'])
        ));
    }

    /**
     * Plain ref: children created `into "$container"` land under the container, in patch order.
     */
    #[Test]
    public function createIntoPlainRefAppendsChildrenInPatchOrder(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->mainId->value, self::CONTAINER_NODE_TYPE, 'into', [], 'container'),
            $this->createTextPatch('$container', 'into', 'one'),
            $this->createTextPatch('$container', 'into', 'two'),
            $this->createTextPatch('$container', 'into', 'three'),
        ]);

        $this->assertSucceeded($result, 4);

        $rows = $result->getResults();
        $this->assertArrayNotHasKey('ref', $rows[0], 'Success rows do not echo the ref');
        $this->assertSame([$this->existingTextId->value, $rows[0]['nodeId']], $this->childIds($this->mainId));
        $this->assertSame(
            [$rows[1]['nodeId'], $rows[2]['nodeId'], $rows[3]['nodeId']],
            $this->childIds(NodeAggregateId::fromString($rows[0]['nodeId']))
        );
    }

    /**
     * Path form: a text created `into "$page/main"` lands in the tethered `main` of the page created
     * earlier in the same batch.
     */
    #[Test]
    public function createIntoAutoCreatedChildOfRefLandsInThatChild(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->pageId->value, self::PAGE_NODE_TYPE, 'into', ['title' => 'Sub'], 'page'),
            $this->createTextPatch('$page/main', 'into', 'in sub main'),
        ]);

        $this->assertSucceeded($result, 2);

        $rows = $result->getResults();
        $subgraph = $this->subgraph($this->currentUserWorkspace);
        $subPageId = NodeAggregateId::fromString($rows[0]['nodeId']);
        $this->assertSame($this->pageId->value, $subgraph->findParentNode($subPageId)?->aggregateId->value);
        $subMain = $subgraph->findNodeByPath(NodeName::fromString('main'), $subPageId);
        $this->assertNotNull($subMain);
        $this->assertSame([$rows[1]['nodeId']], $this->childIds($subMain->aggregateId));
        $this->assertSame(self::TEXT_NODE_TYPE, $subgraph->findNodeById(NodeAggregateId::fromString($rows[1]['nodeId']))?->nodeTypeName->value);
        $this->assertSame([$this->existingTextId->value], $this->childIds($this->mainId), 'The test page\'s own main is untouched');
    }

    /**
     * An `after` chain anchored on the previous patch's `$ref` keeps patch order behind the stored anchor.
     */
    #[Test]
    public function afterChainOnPreviousRefKeepsPatchOrder(): void
    {
        $result = $this->applyPatches([
            $this->createTextPatch($this->existingTextId->value, 'after', 'a', 'a'),
            $this->createTextPatch('$a', 'after', 'b', 'b'),
            $this->createTextPatch('$b', 'after', 'c', 'c'),
        ]);

        $this->assertSucceeded($result, 3);

        $rows = $result->getResults();
        $this->assertSame(
            [$this->existingTextId->value, $rows[0]['nodeId'], $rows[1]['nodeId'], $rows[2]['nodeId']],
            $this->childIds($this->mainId)
        );
    }

    /**
     * The stored `main` imposes the constraints its page type declares for it, as the content repository
     * does when it handles the command. Neos 8 only failed there at execution and rolled back; Neos 9 cannot
     * roll back, so the validator refuses the batch before its ref'd creates are written.
     */
    #[Test]
    public function aForbiddenTypeBelowAStoredTetheredChildRefusesTheRefBatchBeforeAnythingIsWritten(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->mainId->value, self::CONTAINER_NODE_TYPE, 'into', [], 'container'),
            $this->createTextPatch('$container', 'into', 'one'),
            $this->createTextPatch($this->existingTextId->value, 'after', 'a', 'a'),
            $this->createTextPatch('$a', 'after', 'b'),
            $this->createPatch($this->mainId->value, self::FORBIDDEN_NODE_TYPE, 'into'),
        ]);

        $this->assertFalse($result->isSuccess(), 'Batch should be refused: ' . json_encode($result));
        $this->assertFalse($result->isRollbackPerformed());
        $this->assertSame(4, $result->getError()->getPatchIndex());
        $this->assertSame($this->mainId->value, $result->getError()->getNodeId());
        $this->assertNull($result->getError()->getRef());
        $this->assertStringContainsString(
            sprintf('is not allowed as child of node "%s" (Neos.Neos:ContentCollection)', $this->mainId->value),
            $result->getError()->getMessage()
        );
        $this->assertStringContainsString('"' . self::TEXT_NODE_TYPE . '"', $result->getError()->getMessage());

        $this->assertSame([$this->existingTextId->value], $this->childIds($this->mainId));
    }

    /**
     * A ref batch refused by the validator (forbidden type into `$page/main`, caught through the page type's
     * constraints for its tethered child) is never attempted: nothing is written, `error.ref` carries the anchor.
     */
    #[Test]
    public function validationRefusalOfRefBatchLeavesNoNodes(): void
    {
        $result = $this->applyPatches([
            $this->createPatch($this->pageId->value, self::PAGE_NODE_TYPE, 'into', ['title' => 'Sub'], 'page'),
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

        $this->assertSame([$this->mainId->value], $this->childIds($this->pageId), 'Only the page\'s own main remains below it');
        $this->assertSame([$this->existingTextId->value], $this->childIds($this->mainId));
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     */
    private function applyPatches(array $patches): PatchResult
    {
        $language = $this->primaryLanguage();

        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            $language === null ? [] : [$this->languageDimensionId()->value => [$language]]
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

    /**
     * Child node ids in sibling order, read from the user workspace the patches were applied to.
     *
     * @return array<int, string>
     */
    private function childIds(NodeAggregateId $parentId): array
    {
        $ids = [];
        foreach ($this->subgraph($this->currentUserWorkspace)->findChildNodes($parentId, FindChildNodesFilter::create()) as $childNode) {
            $ids[] = $childNode->aggregateId->value;
        }

        return $ids;
    }
}

<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PatchValidator;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The static pre-pass over a batch: batch-local references (`ref`, `$<ref>`, `$<ref>/<childName>`) and
 * the child constraints each kind of anchor imposes, with the exact messages the caller acts on.
 *
 * A functional test rather than a mock-based one: Neos 9's Node, NodeTypeManager and ContentRepository
 * are final, so the validator runs against the testing node types in a real subgraph.
 */
class PatchValidatorTest extends FunctionalTestCase
{
    private const PAGE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const TEXT = 'NEOSidekick.AiAssistant.Testing:Text';
    private const CONTAINER = 'NEOSidekick.AiAssistant.Testing:Container';
    private const FORBIDDEN = 'NEOSidekick.AiAssistant.Testing:ForbiddenContent';

    /**
     * What `RestrictedPage.main` allows, sorted: the page type's constraints for its tethered child.
     */
    private const MAIN_ALLOWS = '"NEOSidekick.AiAssistant.Testing:Container", "NEOSidekick.AiAssistant.Testing:TemplatedContainer", '
        . '"NEOSidekick.AiAssistant.Testing:TemplatedText", "NEOSidekick.AiAssistant.Testing:Text"';

    protected array $siteHosts = ['example.com'];

    private string $pageId;
    private string $mainId;
    private string $siteMainId;
    private string $storedTextId;
    private string $siteId;
    private string $otherPageId;
    private string $samePathSegmentPageId;

    protected function setUpContentInLive(): void
    {
        $site = $this->getNodeByPath('/sites/example');
        $this->assertNotNull($site);
        $siteMain = $this->subgraph()->findNodeByPath(NodeName::fromString('main'), $site->aggregateId);
        $this->assertNotNull($siteMain);
        $this->siteMainId = $siteMain->aggregateId->value;

        $pageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $pageId,
            NodeTypeName::fromString(self::PAGE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $site->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Validator Test', 'uriPathSegment' => 'validator-test'])
        )->withNodeName(NodeName::fromString('validator-test')));
        $this->pageId = $pageId->value;

        $main = $this->subgraph()->findNodeByPath(NodeName::fromString('main'), $pageId);
        $this->assertNotNull($main);
        $this->mainId = $main->aggregateId->value;

        $this->siteId = $site->aggregateId->value;

        // A second page, and inside it a page with the path segment the first page uses: moving it up
        // to the site collides with that name.
        $otherPageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $otherPageId,
            NodeTypeName::fromString(self::PAGE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $site->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Other', 'uriPathSegment' => 'other'])
        )->withNodeName(NodeName::fromString('other')));
        $this->otherPageId = $otherPageId->value;

        $samePathSegmentPageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $samePathSegmentPageId,
            NodeTypeName::fromString(self::PAGE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $otherPageId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Same Segment', 'uriPathSegment' => 'validator-test'])
        )->withNodeName(NodeName::fromString('validator-test')));
        $this->samePathSegmentPageId = $samePathSegmentPageId->value;

        $storedTextId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $storedTextId,
            NodeTypeName::fromString(self::TEXT),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $siteMain->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['text' => 'stored'])
        ));
        $this->storedTextId = $storedTextId->value;
    }

    #[Test]
    public function aRefBatchWithPlainAndChildAnchorsPassesValidation(): void
    {
        $this->validate([
            $this->create($this->pageId, self::PAGE, 'into', 'page'),
            $this->create('$page/main', self::TEXT, 'into', 'text'),
            $this->create('$text', self::TEXT, 'after'),
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            $this->create('$container', self::TEXT, 'into'),
            ['operation' => 'updateNode', 'nodeId' => '$text', 'properties' => []],
            ['operation' => 'moveNode', 'nodeId' => '$text', 'targetNodeId' => '$container', 'position' => 'into'],
            ['operation' => 'deleteNode', 'nodeId' => '$page'],
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anUndefinedRefListsTheRefsDeclaredBeforeThePatch(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'a'),
            $this->create($this->mainId, self::CONTAINER, 'into', 'b'),
            $this->create('$c', self::TEXT, 'into'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame('$c', $exception->getNodeId());
        self::assertSame(
            'Undefined reference "$c" in "positionRelativeToNodeId" at patch 2: no earlier createNode patch declares "ref": "c". Refs declared before patch 2: "a", "b".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aRefDeclaredByTheSamePatchIsUndefined(): void
    {
        $exception = $this->expectFailure([
            $this->create('$self', self::TEXT, 'into', 'self'),
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame(
            'Undefined reference "$self" in "positionRelativeToNodeId" at patch 0: no earlier createNode patch declares "ref": "self". No refs are declared before patch 0; add "ref" to the createNode patch that creates this node and place it earlier in the batch.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aDuplicateRefNamesTheDeclaringPatch(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::TEXT, 'into', 'a'),
            $this->create($this->mainId, self::TEXT, 'into', 'a'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertNull($exception->getNodeId());
        self::assertSame(
            'Duplicate ref "a" at patch 1: it was already declared by patch 0. Refs must be unique within the batch; rename one of them.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aRefOnANonCreatePatchIsRejected(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'updateNode', 'nodeId' => $this->mainId, 'properties' => [], 'ref' => 'x'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame('updateNode', $exception->getOperation());
        self::assertSame(
            '"ref" is only allowed on createNode patches, but patch 0 (updateNode) declares ref "x". Remove "ref" from this patch; a updateNode patch addresses its node through "nodeId".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function anInvalidRefNameIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::TEXT, 'into', '1st item'),
        ]);

        self::assertSame(
            'Invalid ref "1st item" at patch 0: a ref must be a letter followed by up to 63 letters, digits, "_" or "-".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function anUnknownChildNameListsTheValidNames(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->pageId, self::PAGE, 'into', 'page'),
            $this->create('$page/content', self::TEXT, 'into'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('$page/content', $exception->getNodeId());
        self::assertSame(
            'Unknown child "$page/content" in "positionRelativeToNodeId" at patch 1: node type "' . self::PAGE . '" (ref "page") has no auto-created child node named "content". Valid child names: "main".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aChildPathOnATypeWithoutAutoCreatedChildrenSaysSo(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'box'),
            ['operation' => 'deleteNode', 'nodeId' => '$box/main'],
        ]);

        self::assertSame('deleteNode', $exception->getOperation());
        self::assertSame(
            'Unknown child "$box/main" in "nodeId" at patch 1: node type "' . self::CONTAINER . '" (ref "box") has no auto-created child node named "main". It has no auto-created child nodes; use "$box" to address the node itself.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aMalformedAnchorIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->pageId, self::PAGE, 'into', 'page'),
            $this->create('$page/main/extra', self::TEXT, 'into'),
        ]);

        self::assertSame('$page/main/extra', $exception->getNodeId());
        self::assertSame(
            'Malformed reference "$page/main/extra" in "positionRelativeToNodeId" at patch 1: only one "/<childName>" segment is allowed after the ref. Use "$<ref>" for a node created earlier in this batch, or "$<ref>/<childName>" for one of its auto-created child nodes.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aConstraintViolationViaAPlainRefListsTheAllowedChildTypes(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            $this->create('$container', self::FORBIDDEN, 'into'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('$container', $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of "$container" (' . self::CONTAINER . '). Allowed child types: "' . self::TEXT . '".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aChildPathAnchorAppliesTheGrandchildConstraints(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->pageId, self::PAGE, 'into', 'page'),
            $this->create('$page/main', self::FORBIDDEN, 'into'),
        ]);

        self::assertSame('$page/main', $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of "$page/main" (Neos.Neos:ContentCollection). Allowed child types: ' . self::MAIN_ALLOWS . '.',
            $exception->getMessage()
        );
    }

    /**
     * Neos 9's content repository applies the owner's constraints to a stored tethered child too
     * (ConstraintChecks::requireConstraintsImposedByAncestorsAreMet), and without a rollback a batch
     * that only fails there would be half written - so the validator applies them as well.
     */
    #[Test]
    public function aStoredTetheredAnchorAppliesTheConstraintsItsOwnerDeclares(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::FORBIDDEN, 'into'),
        ]);

        self::assertSame($this->mainId, $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of node "' . $this->mainId . '" (Neos.Neos:ContentCollection). Allowed child types: ' . self::MAIN_ALLOWS . '.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aSiblingOfARefIsCheckedAgainstTheDeclaringParent(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            $this->create('$container', self::TEXT, 'into', 'text'),
            $this->create('$text', self::FORBIDDEN, 'before'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of "$container" (' . self::CONTAINER . '). Allowed child types: "' . self::TEXT . '".',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aMoveOfARefIntoAForbiddingRefTargetIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            $this->create($this->siteMainId, self::FORBIDDEN, 'into', 'bad'),
            ['operation' => 'moveNode', 'nodeId' => '$bad', 'targetNodeId' => '$container', 'position' => 'into'],
        ]);

        self::assertSame('moveNode', $exception->getOperation());
        self::assertSame('$bad', $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" of "$bad" is not allowed as child of "$container" (' . self::CONTAINER . '). Allowed child types: "' . self::TEXT . '".',
            $exception->getMessage()
        );
    }

    /**
     * A deleted ref stops being addressable: the pending node is dropped from the map, so the later
     * anchor on it hits the undefined-reference refusal.
     */
    #[Test]
    public function anAnchorOnADeletedRefIsUndefined(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'a'),
            ['operation' => 'deleteNode', 'nodeId' => '$a'],
            $this->create('$a', self::TEXT, 'into'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame('$a', $exception->getNodeId());
        self::assertSame(
            'Undefined reference "$a" in "positionRelativeToNodeId" at patch 2: no earlier createNode patch declares "ref": "a". No refs are declared before patch 2; add "ref" to the createNode patch that creates this node and place it earlier in the batch.',
            $exception->getMessage()
        );
    }

    /**
     * A moveNode of a pending node re-parents it for later anchors: the `after "$t"` sibling is checked
     * against the move target (Container, which only allows Text), not against the `main` the text was
     * declared under - without that update the batch would pass.
     */
    #[Test]
    public function aSiblingOfAMovedRefIsCheckedAgainstTheNewParent(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->siteMainId, self::TEXT, 'into', 't'),
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            ['operation' => 'moveNode', 'nodeId' => '$t', 'targetNodeId' => '$container', 'position' => 'into'],
            $this->create('$t', self::FORBIDDEN, 'after'),
        ]);

        self::assertSame(3, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame('$t', $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of "$container" (' . self::CONTAINER . '). Allowed child types: "' . self::TEXT . '".',
            $exception->getMessage()
        );
    }

    /**
     * The same for a stored node: after the batch moved it, a sibling anchored on it is checked against
     * the parent the batch gives it. Without that the batch would pass and the move would already be
     * written when the content repository refuses the last patch.
     */
    #[Test]
    public function aSiblingOfAMovedStoredNodeIsCheckedAgainstTheNewParent(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'container'),
            ['operation' => 'moveNode', 'nodeId' => $this->storedTextId, 'targetNodeId' => '$container', 'position' => 'into'],
            $this->create($this->storedTextId, self::FORBIDDEN, 'after'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame($this->storedTextId, $exception->getNodeId());
        self::assertSame(
            'NodeType "' . self::FORBIDDEN . '" is not allowed as child of "$container" (' . self::CONTAINER . '). Allowed child types: "' . self::TEXT . '".',
            $exception->getMessage()
        );
    }

    /**
     * Deleting a ref takes the refs the batch would create below it with it: the nodes are never created,
     * so a later patch addressing one of them is refused instead of failing after the deletion happened.
     */
    #[Test]
    public function anAnchorOnARefBelowADeletedRefIsUndefined(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'parent'),
            $this->create('$parent', self::TEXT, 'into', 'child'),
            ['operation' => 'deleteNode', 'nodeId' => '$parent'],
            ['operation' => 'updateNode', 'nodeId' => '$child', 'properties' => []],
        ]);

        self::assertSame(3, $exception->getPatchIndex());
        self::assertSame('updateNode', $exception->getOperation());
        self::assertSame('$child', $exception->getNodeId());
        self::assertSame(
            'Undefined reference "$child" in "nodeId" at patch 3: no earlier createNode patch declares "ref": "child". No refs are declared before patch 3; add "ref" to the createNode patch that creates this node and place it earlier in the batch.',
            $exception->getMessage()
        );
    }

    /**
     * The content repository refuses to remove or move a tethered node (ConstraintChecks::
     * requireNodeAggregateNotToBeTethered() / requireNodeAggregateToBeUntethered()), so the batch is
     * refused before the page it would have created is written.
     */
    #[Test]
    public function anAutoCreatedChildOfARefCannotBeDeleted(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->pageId, self::PAGE, 'into', 'page'),
            ['operation' => 'deleteNode', 'nodeId' => '$page/main'],
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('deleteNode', $exception->getOperation());
        self::assertSame('$page/main', $exception->getNodeId());
        self::assertSame(
            '"$page/main" is an auto-created child node and cannot be deleted: it is part of its parent node type. Delete the nodes inside it instead, or its parent node.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aStoredAutoCreatedChildCannotBeMoved(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'moveNode', 'nodeId' => $this->mainId, 'targetNodeId' => $this->siteMainId, 'position' => 'into'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame('moveNode', $exception->getOperation());
        self::assertSame($this->mainId, $exception->getNodeId());
        self::assertSame(
            'Node "' . $this->mainId . '" is an auto-created child node and cannot be moved: it is part of its parent node type. Move the nodes inside it instead, or its parent node.',
            $exception->getMessage()
        );
    }

    /**
     * A ref is unique for the whole request, so deleting the node it names does not free it again.
     */
    #[Test]
    public function aRefStaysTakenAfterItsNodeWasDeleted(): void
    {
        $exception = $this->expectFailure([
            $this->create($this->mainId, self::CONTAINER, 'into', 'x'),
            ['operation' => 'deleteNode', 'nodeId' => '$x'],
            $this->create($this->mainId, self::TEXT, 'into', 'x'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame(
            'Duplicate ref "x" at patch 2: it was already declared by patch 0. Refs must be unique within the batch; rename one of them.',
            $exception->getMessage()
        );
    }

    /**
     * The content repository refuses a move into the moved node's own subtree
     * (ConstraintChecks::requireNodeAggregateToNotBeDescendant()).
     */
    #[Test]
    public function aNodeCannotBeMovedIntoItsOwnSubtree(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'moveNode', 'nodeId' => $this->otherPageId, 'targetNodeId' => $this->samePathSegmentPageId, 'position' => 'into'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame('moveNode', $exception->getOperation());
        self::assertSame($this->otherPageId, $exception->getNodeId());
        self::assertSame(
            'Node "' . $this->otherPageId . '" cannot be moved into node "' . $this->samePathSegmentPageId . '": the target is the node itself or one of its descendants.',
            $exception->getMessage()
        );
    }

    /**
     * Node names are unique among siblings (ConstraintChecks::requireNodeNameToBeUncovered()), and a
     * document's name is its path segment - two pages with the same path segment cannot end up under
     * the same parent.
     */
    #[Test]
    public function aMoveIsRejectedWhenTheNewParentAlreadyHasAChildOfThatName(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'moveNode', 'nodeId' => $this->samePathSegmentPageId, 'targetNodeId' => $this->siteId, 'position' => 'into'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame($this->samePathSegmentPageId, $exception->getNodeId());
        self::assertSame(
            'Node "' . $this->samePathSegmentPageId . '" cannot be moved into node "' . $this->siteId . '": it already has a child node named "validator-test", and node names are unique among siblings.',
            $exception->getMessage()
        );
    }

    /**
     * The name is only taken as long as the batch leaves the sibling where it is.
     */
    #[Test]
    public function aMoveIntoTheNameOfASiblingTheBatchDeletesPasses(): void
    {
        $this->validate([
            ['operation' => 'deleteNode', 'nodeId' => $this->pageId],
            ['operation' => 'moveNode', 'nodeId' => $this->samePathSegmentPageId, 'targetNodeId' => $this->siteId, 'position' => 'into'],
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function anAnchorOnANodeTheBatchDeletedIsRejected(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'deleteNode', 'nodeId' => $this->storedTextId],
            ['operation' => 'updateNode', 'nodeId' => $this->storedTextId, 'properties' => []],
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('updateNode', $exception->getOperation());
        self::assertSame($this->storedTextId, $exception->getNodeId());
        self::assertSame(
            'Node "' . $this->storedTextId . '" in "nodeId" at patch 1 was deleted by patch 0 of this batch and cannot be addressed afterwards.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function aStoredIdThatDoesNotExistPointsToRefs(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'deleteNode', 'nodeId' => 'ffffffff-0000-0000-0000-000000000000'],
        ]);

        self::assertSame('ffffffff-0000-0000-0000-000000000000', $exception->getNodeId());
        self::assertSame(
            'Node with identifier "ffffffff-0000-0000-0000-000000000000" does not exist. Use the id of an existing node, or "$<ref>" to address a node created by an earlier createNode patch of this batch.',
            $exception->getMessage()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $patchesData
     */
    private function validate(array $patchesData): void
    {
        $patches = array_map(static fn(array $data): AbstractPatch => AbstractPatch::fromArray($data), $patchesData);
        $this->objectManager->get(PatchValidator::class)->validatePatches(
            $patches,
            $this->contentRepository->getContentSubgraph(WorkspaceName::fromString($this->currentUserWorkspace), $this->dimensionSpacePoint())
        );
    }

    /**
     * @param array<int, array<string, mixed>> $patchesData
     */
    private function expectFailure(array $patchesData): PatchFailedException
    {
        try {
            $this->validate($patchesData);
        } catch (PatchFailedException $exception) {
            return $exception;
        }
        self::fail('Expected the batch to be refused');
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $anchor, string $nodeType, string $position, ?string $ref = null): array
    {
        $patch = [
            'operation' => 'createNode',
            'positionRelativeToNodeId' => $anchor,
            'nodeType' => $nodeType,
            'position' => $position,
        ];
        if ($ref !== null) {
            $patch['ref'] = $ref;
        }

        return $patch;
    }
}

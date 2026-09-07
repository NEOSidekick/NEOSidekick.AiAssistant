<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use Flowpack\NodeTemplates\Domain\NodeCreation\PropertiesProcessor;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PatchValidator;
use NEOSidekick\AiAssistant\Service\PropertyNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Batch-local references in the validator's static pre-pass: `ref` declarations, `$<ref>` and
 * `$<ref>/<childName>` anchors, and the constraint checks answered from the pending-node map.
 *
 * Uses a small real NodeType graph so the constraint semantics are Neos' own:
 *
 * - `T:Page` (allows `T:Page`) with auto-created `main` of type `T:Collection` (allows `*`),
 *   restricted through the page's grandchild constraints to `T:Text`
 * - `T:Container` (allows `T:Text`)
 * - `T:Text`, `T:Forbidden`
 *
 * The stored node `main-id` is a `T:Collection` whose parent is a stored `T:Page`.
 */
class PatchValidatorTest extends TestCase
{
    private const MAIN_ID = 'a1b2c3d4-0000-0000-0000-000000000001';
    private const PAGE_ID = 'a1b2c3d4-0000-0000-0000-000000000002';

    private PatchValidator $validator;

    private Context $context;

    /**
     * @var array<string, NodeType>
     */
    private array $nodeTypes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $nodeTypeManager = $this->createMock(NodeTypeManager::class);
        $this->nodeTypes = [
            'T:Text' => new NodeType('T:Text', [], []),
            'T:Forbidden' => new NodeType('T:Forbidden', [], []),
            'T:Collection' => new NodeType('T:Collection', [], ['constraints' => ['nodeTypes' => ['*' => true]]]),
            'T:Container' => new NodeType('T:Container', [], ['constraints' => ['nodeTypes' => ['*' => false, 'T:Text' => true]]]),
            'T:Page' => new NodeType('T:Page', [], [
                'constraints' => ['nodeTypes' => ['*' => false, 'T:Page' => true]],
                'childNodes' => [
                    'main' => [
                        'type' => 'T:Collection',
                        'constraints' => ['nodeTypes' => ['*' => false, 'T:Text' => true]],
                    ],
                ],
            ]),
        ];
        foreach ($this->nodeTypes as $nodeType) {
            $this->setProperty($nodeType, 'nodeTypeManager', $nodeTypeManager);
        }
        $nodeTypeManager->method('hasNodeType')->willReturnCallback(fn(string $name): bool => isset($this->nodeTypes[$name]));
        $nodeTypeManager->method('getNodeType')->willReturnCallback(fn(string $name): NodeType => $this->nodeTypes[$name]);
        $nodeTypeManager->method('getNodeTypes')->willReturn($this->nodeTypes);

        $pageNode = $this->createStoredNode(self::PAGE_ID, $this->nodeTypes['T:Page'], null);
        $mainNode = $this->createStoredNode(self::MAIN_ID, $this->nodeTypes['T:Collection'], $pageNode);
        $this->context = $this->createMock(Context::class);
        $this->context->method('getNodeByIdentifier')->willReturnCallback(
            static fn(string $identifier): ?NodeInterface => match ($identifier) {
                self::MAIN_ID => $mainNode,
                self::PAGE_ID => $pageNode,
                default => null,
            }
        );

        $this->validator = new PatchValidator();
        $this->setProperty($this->validator, 'nodeTypeManager', $nodeTypeManager);
        $this->setProperty($this->validator, 'propertiesProcessor', $this->createMock(PropertiesProcessor::class));
        $this->setProperty($this->validator, 'propertyNormalizer', $this->createMock(PropertyNormalizer::class));
    }

    /** @test */
    public function aRefBatchWithPlainAndChildAnchorsPassesValidation(): void
    {
        $this->validate([
            $this->create(self::PAGE_ID, 'T:Page', 'into', 'page'),
            $this->create('$page/main', 'T:Text', 'into', 'text'),
            $this->create('$text', 'T:Text', 'after'),
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'container'),
            $this->create('$container', 'T:Text', 'into'),
            ['operation' => 'updateNode', 'nodeId' => '$text', 'properties' => []],
            ['operation' => 'moveNode', 'nodeId' => '$text', 'targetNodeId' => '$container', 'position' => 'into'],
            ['operation' => 'deleteNode', 'nodeId' => '$page'],
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function anUndefinedRefListsTheRefsDeclaredBeforeThePatch(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'a'),
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'b'),
            $this->create('$c', 'T:Text', 'into'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame('$c', $exception->getNodeId());
        self::assertSame(
            'Undefined reference "$c" in "positionRelativeToNodeId" at patch 2: no earlier createNode patch declares "ref": "c". Refs declared before patch 2: "a", "b".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aRefDeclaredByTheSamePatchIsUndefined(): void
    {
        $exception = $this->expectFailure([
            $this->create('$self', 'T:Text', 'into', 'self'),
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame(
            'Undefined reference "$self" in "positionRelativeToNodeId" at patch 0: no earlier createNode patch declares "ref": "self". No refs are declared before patch 0; add "ref" to the createNode patch that creates this node and place it earlier in the batch.',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aDuplicateRefNamesTheDeclaringPatch(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Text', 'into', 'a'),
            $this->create(self::MAIN_ID, 'T:Text', 'into', 'a'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertNull($exception->getNodeId());
        self::assertSame(
            'Duplicate ref "a" at patch 1: it was already declared by patch 0. Refs must be unique within the batch; rename one of them.',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aRefOnANonCreatePatchIsRejected(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'updateNode', 'nodeId' => self::MAIN_ID, 'properties' => [], 'ref' => 'x'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame('updateNode', $exception->getOperation());
        self::assertSame(
            '"ref" is only allowed on createNode patches, but patch 0 (updateNode) declares ref "x". Remove "ref" from this patch; a updateNode patch addresses its node through "nodeId".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function anInvalidRefNameIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Text', 'into', '1st item'),
        ]);

        self::assertSame(
            'Invalid ref "1st item" at patch 0: a ref must be a letter followed by up to 63 letters, digits, "_" or "-".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function anUnknownChildNameListsTheValidNames(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::PAGE_ID, 'T:Page', 'into', 'page'),
            $this->create('$page/content', 'T:Text', 'into'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('$page/content', $exception->getNodeId());
        self::assertSame(
            'Unknown child "$page/content" in "positionRelativeToNodeId" at patch 1: node type "T:Page" (ref "page") has no auto-created child node named "content". Valid child names: "main".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aChildPathOnATypeWithoutAutoCreatedChildrenSaysSo(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'box'),
            ['operation' => 'deleteNode', 'nodeId' => '$box/main'],
        ]);

        self::assertSame('deleteNode', $exception->getOperation());
        self::assertSame(
            'Unknown child "$box/main" in "nodeId" at patch 1: node type "T:Container" (ref "box") has no auto-created child node named "main". It has no auto-created child nodes; use "$box" to address the node itself.',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aMalformedAnchorIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::PAGE_ID, 'T:Page', 'into', 'page'),
            $this->create('$page/main/extra', 'T:Text', 'into'),
        ]);

        self::assertSame('$page/main/extra', $exception->getNodeId());
        self::assertSame(
            'Malformed reference "$page/main/extra" in "positionRelativeToNodeId" at patch 1: only one "/<childName>" segment is allowed after the ref. Use "$<ref>" for a node created earlier in this batch, or "$<ref>/<childName>" for one of its auto-created child nodes.',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aConstraintViolationViaAPlainRefListsTheAllowedChildTypes(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'container'),
            $this->create('$container', 'T:Forbidden', 'into'),
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame('$container', $exception->getNodeId());
        self::assertSame(
            'NodeType "T:Forbidden" is not allowed as child of "$container" (T:Container). Allowed child types: "T:Text".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aChildPathAnchorAppliesTheGrandchildConstraints(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::PAGE_ID, 'T:Page', 'into', 'page'),
            $this->create('$page/main', 'T:Forbidden', 'into'),
        ]);

        self::assertSame('$page/main', $exception->getNodeId());
        self::assertSame(
            'NodeType "T:Forbidden" is not allowed as child of "$page/main" (T:Collection). Allowed child types: "T:Text".',
            $exception->getMessage()
        );
    }

    /**
     * Deliberate: the stored `main` is checked against its own type (`*`), not the page's grandchild
     * constraints, exactly as before refs existed.
     *
     * @test
     */
    public function aStoredAnchorKeepsTheParentTypeCheck(): void
    {
        $this->validate([
            $this->create(self::MAIN_ID, 'T:Forbidden', 'into'),
        ]);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function aSiblingOfARefIsCheckedAgainstTheDeclaringParent(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'container'),
            $this->create('$container', 'T:Text', 'into', 'text'),
            $this->create('$text', 'T:Forbidden', 'before'),
        ]);

        self::assertSame(2, $exception->getPatchIndex());
        self::assertSame(
            'NodeType "T:Forbidden" is not allowed as child of "$container" (T:Container). Allowed child types: "T:Text".',
            $exception->getMessage()
        );
    }

    /** @test */
    public function aMoveOfARefIntoAForbiddingRefTargetIsRejected(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'container'),
            $this->create(self::MAIN_ID, 'T:Forbidden', 'into', 'bad'),
            ['operation' => 'moveNode', 'nodeId' => '$bad', 'targetNodeId' => '$container', 'position' => 'into'],
        ]);

        self::assertSame('moveNode', $exception->getOperation());
        self::assertSame('$bad', $exception->getNodeId());
        self::assertSame(
            'NodeType "T:Forbidden" of "$bad" is not allowed as child of "$container" (T:Container). Allowed child types: "T:Text".',
            $exception->getMessage()
        );
    }

    /**
     * A deleted ref stops being addressable: the pending node is dropped from the map, so the later
     * anchor on it hits the undefined-reference refusal.
     *
     * @test
     */
    public function anAnchorOnADeletedRefIsUndefined(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'a'),
            ['operation' => 'deleteNode', 'nodeId' => '$a'],
            $this->create('$a', 'T:Text', 'into'),
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
     * against the move target (`T:Container`, which forbids `T:Forbidden`), not against the permissive
     * `T:Collection` the text was declared under — without that update the batch would pass.
     *
     * @test
     */
    public function aSiblingOfAMovedRefIsCheckedAgainstTheNewParent(): void
    {
        $exception = $this->expectFailure([
            $this->create(self::MAIN_ID, 'T:Text', 'into', 't'),
            $this->create(self::MAIN_ID, 'T:Container', 'into', 'container'),
            ['operation' => 'moveNode', 'nodeId' => '$t', 'targetNodeId' => '$container', 'position' => 'into'],
            $this->create('$t', 'T:Forbidden', 'after'),
        ]);

        self::assertSame(3, $exception->getPatchIndex());
        self::assertSame('createNode', $exception->getOperation());
        self::assertSame('$t', $exception->getNodeId());
        self::assertSame(
            'NodeType "T:Forbidden" is not allowed as child of "$container" (T:Container). Allowed child types: "T:Text".',
            $exception->getMessage()
        );
    }

    /** @test */
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
        $this->validator->validatePatches($patches, $this->context);
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

    private function createStoredNode(string $identifier, NodeType $nodeType, ?NodeInterface $parent): NodeInterface
    {
        $node = $this->createMock(NodeInterface::class);
        $node->method('getIdentifier')->willReturn($identifier);
        $node->method('getNodeType')->willReturn($nodeType);
        $node->method('getParent')->willReturn($parent);

        return $node;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = new ReflectionProperty($target, $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}

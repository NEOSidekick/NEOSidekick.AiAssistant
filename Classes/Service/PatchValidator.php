<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrors;
use Flowpack\NodeTemplates\Domain\NodeCreation\PropertiesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\ReferencesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\TransientNode;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Dto\NodeAggregateIdsByNodePaths;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreateNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\DeleteNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\MoveNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\RefAnchor;
use NEOSidekick\AiAssistant\Dto\Patch\UpdateNodePatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PatchValidation\NodeDescriptor;

/**
 * Validates patches before execution using NodeTemplates' PropertiesProcessor.
 *
 * This service validates properties against NodeType schemas before applying
 * any changes to the content repository, ensuring that invalid patches are
 * caught early without affecting the database.
 *
 * The validation is a single static pre-pass over the batch. Nodes that a createNode patch declares
 * with `ref` are recorded in a pending-node map (ref → node type and parent descriptor) so that later
 * patches may anchor on `$<ref>` or `$<ref>/<childName>` and get the same checks as stored anchors.
 */
class PatchValidator
{
    /**
     * Longest list of node type names quoted in an error message.
     */
    private const MAX_LISTED_NODE_TYPES = 20;

    /**
     * @Flow\Inject
     * @var PropertiesProcessor
     */
    protected $propertiesProcessor;

    /**
     * @Flow\Inject
     * @var ReferencesProcessor
     */
    protected $referencesProcessor;

    /**
     * @Flow\Inject
     * @var PropertyNormalizer
     */
    protected $propertyNormalizer;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * Nodes declared with `ref` by the createNode patches validated so far, keyed by ref.
     *
     * @var array<string, NodeDescriptor>
     */
    private array $pendingNodes = [];

    /**
     * Every `ref` the batch declares, keyed by ref, with the patch index that declared it. Kept even
     * when the node is deleted again, because a ref is unique for the whole request.
     *
     * @var array<string, int>
     */
    private array $declaredRefs = [];

    /**
     * The new parent of every node the batch moves, keyed by node aggregate id: a later
     * `before`/`after` anchor on a moved node is checked against where the batch puts it, not against
     * where it still is while the batch is being validated.
     *
     * @var array<string, NodeDescriptor>
     */
    private array $movedNodeParents = [];

    /**
     * The stored nodes the batch deletes, keyed by node aggregate id, with the patch index that
     * deleted them: a later patch cannot address a node that is gone by then.
     *
     * @var array<string, int>
     */
    private array $deletedStoredNodeIds = [];

    /**
     * Validate a whole batch before execution, in patch order, stopping at the first error.
     *
     * @param array<int, AbstractPatch> $patches
     * @param ContentSubgraphInterface $subgraph The content subgraph (workspace + dimensions) to validate against
     * @throws PatchFailedException If validation fails
     */
    public function validatePatches(array $patches, ContentSubgraphInterface $subgraph): void
    {
        $this->pendingNodes = [];
        $this->declaredRefs = [];
        $this->movedNodeParents = [];
        $this->deletedStoredNodeIds = [];
        try {
            foreach ($patches as $patchIndex => $patch) {
                $this->validatePatch($patch, $patchIndex, $subgraph);
            }
        } finally {
            $this->pendingNodes = [];
            $this->declaredRefs = [];
            $this->movedNodeParents = [];
            $this->deletedStoredNodeIds = [];
        }
    }

    /**
     * Validate a patch before execution.
     *
     * @param AbstractPatch $patch The patch to validate
     * @param int $patchIndex The index of the patch in the batch
     * @param ContentSubgraphInterface $subgraph The content subgraph (workspace + dimensions) to validate against
     * @throws PatchFailedException If validation fails
     */
    private function validatePatch(AbstractPatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        $this->validateRefDeclaration($patch, $patchIndex);

        if ($patch instanceof CreateNodePatch) {
            $this->validateCreateNodePatch($patch, $patchIndex, $subgraph);
        } elseif ($patch instanceof UpdateNodePatch) {
            $this->validateUpdateNodePatch($patch, $patchIndex, $subgraph);
        } elseif ($patch instanceof MoveNodePatch) {
            $this->validateMoveNodePatch($patch, $patchIndex, $subgraph);
        } elseif ($patch instanceof DeleteNodePatch) {
            $this->validateDeleteNodePatch($patch, $patchIndex, $subgraph);
        }
    }

    /**
     * `ref` is only allowed on createNode, must match the ref pattern and must be unique in the batch.
     *
     * @throws PatchFailedException
     */
    private function validateRefDeclaration(AbstractPatch $patch, int $patchIndex): void
    {
        $ref = $patch->getRef();
        if ($ref === null) {
            return;
        }

        if (!$patch instanceof CreateNodePatch) {
            throw new PatchFailedException(
                sprintf(
                    '"ref" is only allowed on createNode patches, but patch %d (%s) declares ref "%s". '
                    . 'Remove "ref" from this patch; a %s patch addresses its node through "nodeId".',
                    $patchIndex,
                    $patch->getOperation(),
                    $ref,
                    $patch->getOperation()
                ),
                $patchIndex,
                $patch->getOperation()
            );
        }

        if (preg_match(RefAnchor::REF_PATTERN, $ref) !== 1) {
            throw new PatchFailedException(
                sprintf(
                    'Invalid ref "%s" at patch %d: a ref must be %s.',
                    $ref,
                    $patchIndex,
                    RefAnchor::REF_PATTERN_DESCRIPTION
                ),
                $patchIndex,
                'createNode'
            );
        }

        if (isset($this->declaredRefs[$ref])) {
            throw new PatchFailedException(
                sprintf(
                    'Duplicate ref "%s" at patch %d: it was already declared by patch %d. '
                    . 'Refs must be unique within the batch; rename one of them.',
                    $ref,
                    $patchIndex,
                    $this->declaredRefs[$ref]
                ),
                $patchIndex,
                'createNode'
            );
        }
    }

    /**
     * Validate a createNode patch.
     *
     * For 'into' position, the positionRelativeToNodeId is the actual parent.
     * For 'before'/'after' positions, the positionRelativeToNodeId is a reference sibling node,
     * and the new node will be created under that sibling's parent.
     *
     * @param CreateNodePatch $patch
     * @param int $patchIndex
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateCreateNodePatch(CreateNodePatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());

        // Validate nodeType exists
        $nodeType = $this->getNodeType($patch->getNodeType(), $patchIndex, 'createNode', $contentRepository);

        // Validate nodeType is not abstract (abstract types cannot be instantiated)
        if ($nodeType->isAbstract()) {
            throw new PatchFailedException(
                sprintf('Cannot create node of abstract NodeType "%s"', $nodeType->name->value),
                $patchIndex,
                'createNode'
            );
        }

        // Validate reference node exists (parent for 'into', sibling for 'before'/'after')
        $referenceNode = $this->resolveAnchor($patch->getPositionRelativeToNodeId(), 'positionRelativeToNodeId', $patchIndex, 'createNode', $subgraph, $contentRepository);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'createNode', $patch->getPositionRelativeToNodeId());

        // Determine actual parent based on position
        // For 'into': the referenceNode is the parent
        // For 'before'/'after': the referenceNode is a sibling, so the actual parent is its parent
        if ($patch->getPosition() === 'into') {
            $actualParent = $referenceNode;
        } else {
            $actualParent = $this->resolveParent($referenceNode, $subgraph, $contentRepository);
            if ($actualParent === null) {
                throw new PatchFailedException(
                    sprintf('Reference node "%s" has no parent', $patch->getPositionRelativeToNodeId()),
                    $patchIndex,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }
        }

        if ($actualParent->getNodeType() === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" of the parent node is not known to the schema', $actualParent->getStoredNode()?->nodeTypeName->value),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate node type is allowed as child of the actual parent
        if (!$actualParent->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                $this->buildChildConstraintMessage($nodeType, $actualParent, $contentRepository),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'createNode', $subgraph);

        if ($patch->getRef() !== null) {
            $this->pendingNodes[$patch->getRef()] = NodeDescriptor::forPendingNode($patch->getRef(), $patchIndex, $nodeType, $actualParent);
            $this->declaredRefs[$patch->getRef()] = $patchIndex;
        }
    }

    /**
     * Validate an updateNode patch.
     *
     * @param UpdateNodePatch $patch
     * @param int $patchIndex
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateUpdateNodePatch(UpdateNodePatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());

        // Validate node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'updateNode', $subgraph, $contentRepository);

        $nodeType = $node->getNodeType();
        if ($nodeType === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" of node "%s" is not known to the schema', $node->getStoredNode()?->nodeTypeName->value, $patch->getNodeId()),
                $patchIndex,
                'updateNode',
                $patch->getNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'updateNode', $subgraph);
    }

    /**
     * Validate a moveNode patch.
     *
     * @param MoveNodePatch $patch
     * @param int $patchIndex
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateMoveNodePatch(MoveNodePatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());

        // Validate source node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'moveNode', $subgraph, $contentRepository);

        // Validate target node exists
        $targetNode = $this->resolveAnchor($patch->getTargetNodeId(), 'targetNodeId', $patchIndex, 'moveNode', $subgraph, $contentRepository);

        $this->requireUntetheredNode($node, $patchIndex, 'moveNode', $patch->getNodeId(), 'moved');

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'moveNode', $patch->getNodeId());

        // Determine the new parent node based on position
        if ($patch->getPosition() === 'into') {
            $newParentNode = $targetNode;
        } else {
            // For 'before' or 'after', parent will be target's parent
            $newParentNode = $this->resolveParent($targetNode, $subgraph, $contentRepository);
            if ($newParentNode === null) {
                throw new PatchFailedException(
                    sprintf('Target node "%s" has no parent node', $patch->getTargetNodeId()),
                    $patchIndex,
                    'moveNode',
                    $patch->getNodeId()
                );
            }
        }

        // Unknown node types must fail here like in the create/update validations — a silent
        // skip would defer the failure to CR execution time, mid-batch and without rollback.
        $this->requireKnownNodeType($newParentNode, $patchIndex, 'moveNode');
        $nodeType = $this->requireKnownNodeType($node, $patchIndex, 'moveNode');

        // Validate node type constraints in the new location
        if (!$newParentNode->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                $this->buildChildConstraintMessage($nodeType, $newParentNode, $contentRepository, $node),
                $patchIndex,
                'moveNode',
                $patch->getNodeId()
            );
        }

        $this->requireMoveTargetToBeValid($node, $newParentNode, $subgraph, $contentRepository, $patchIndex, $patch->getNodeId());

        // A moved node reports its new parent to later before/after anchors
        if ($node->isPendingNode()) {
            $this->pendingNodes[$node->getRef()] = $node->withParent($newParentNode);
        }
        $movedStoredNode = $node->getStoredNode();
        if ($movedStoredNode !== null) {
            $this->movedNodeParents[$movedStoredNode->aggregateId->value] = $newParentNode;
        }
    }

    /**
     * Validate a deleteNode patch.
     *
     * Deleting a pending node drops it from the pending-node map, so a later anchor on its ref is
     * refused as undefined instead of resolving to a node the batch no longer creates.
     *
     * @param DeleteNodePatch $patch
     * @param int $patchIndex
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateDeleteNodePatch(DeleteNodePatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());

        // Validate node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'deleteNode', $subgraph, $contentRepository);

        $this->requireUntetheredNode($node, $patchIndex, 'deleteNode', $patch->getNodeId(), 'deleted');

        // A deleted node stops being addressable, and it takes the nodes the batch would create
        // below it with it: later anchors on those refs are undefined
        $this->forgetPendingNodesBelow($node);
        $deletedStoredNode = $node->getStoredNode();
        if ($deletedStoredNode !== null) {
            $this->deletedStoredNodeIds[$deletedStoredNode->aggregateId->value] = $patchIndex;
        }
    }

    /**
     * Auto-created child nodes are part of their parent's node type: the content repository refuses
     * to move or remove them (ConstraintChecks::requireNodeAggregateToBeUntethered()), so a batch
     * that asks for it is refused before any of its patches is executed.
     *
     * @throws PatchFailedException
     */
    private function requireUntetheredNode(NodeDescriptor $node, int $patchIndex, string $operation, string $anchor, string $verb): void
    {
        if (!$node->isTetheredNode()) {
            return;
        }

        throw new PatchFailedException(
            sprintf(
                '%s is an auto-created child node and cannot be %s: it is part of its parent node type. '
                . '%s the nodes inside it instead, or its parent node.',
                ucfirst($node->getLabel()),
                $verb,
                $operation === 'moveNode' ? 'Move' : 'Delete'
            ),
            $patchIndex,
            $operation,
            $anchor
        );
    }

    /**
     * What the content repository checks for a move besides the child constraints: the target must not
     * be inside the node being moved (requireNodeAggregateToNotBeDescendant()) and the node's name must
     * be free under the new parent (requireNodeNameToBeUncovered()).
     *
     * Both are checked against the state the batch has built up, so a sibling this batch moves away or
     * deletes does not count as a collision.
     *
     * @throws PatchFailedException
     */
    private function requireMoveTargetToBeValid(NodeDescriptor $node, NodeDescriptor $newParent, ContentSubgraphInterface $subgraph, ContentRepository $contentRepository, int $patchIndex, string $anchor): void
    {
        for ($ancestor = $newParent; $ancestor !== null; $ancestor = $this->resolveParent($ancestor, $subgraph, $contentRepository)) {
            if (!$this->isSameNode($ancestor, $node)) {
                continue;
            }

            throw new PatchFailedException(
                sprintf(
                    '%s cannot be moved into %s: the target is the node itself or one of its descendants.',
                    ucfirst($node->getLabel()),
                    $newParent->getLabel()
                ),
                $patchIndex,
                'moveNode',
                $anchor
            );
        }

        $movedStoredNode = $node->getStoredNode();
        $newParentStoredNode = $newParent->getStoredNode();
        if ($movedStoredNode === null || $movedStoredNode->name === null || $newParentStoredNode === null) {
            return;
        }

        $sibling = $subgraph->findNodeByPath($movedStoredNode->name, $newParentStoredNode->aggregateId);
        if ($sibling === null || $sibling->aggregateId->equals($movedStoredNode->aggregateId)) {
            return;
        }
        $siblingId = $sibling->aggregateId->value;
        $siblingLeavesTheParent = isset($this->deletedStoredNodeIds[$siblingId])
            || (isset($this->movedNodeParents[$siblingId]) && !$this->isSameNode($this->movedNodeParents[$siblingId], $newParent));
        if ($siblingLeavesTheParent) {
            return;
        }

        throw new PatchFailedException(
            sprintf(
                '%s cannot be moved into %s: it already has a child node named "%s", and node names are unique among siblings.',
                ucfirst($node->getLabel()),
                $newParent->getLabel(),
                $movedStoredNode->name->value
            ),
            $patchIndex,
            'moveNode',
            $anchor
        );
    }

    /**
     * Whether both descriptors address the same node: the same stored node, or the same ref.
     */
    private function isSameNode(NodeDescriptor $left, NodeDescriptor $right): bool
    {
        $leftStoredNode = $left->getStoredNode();
        $rightStoredNode = $right->getStoredNode();
        if ($leftStoredNode !== null && $rightStoredNode !== null) {
            return $leftStoredNode->aggregateId->equals($rightStoredNode->aggregateId);
        }

        return $left->getRef() !== null && $left->getRef() === $right->getRef();
    }

    /**
     * Drops a deleted node and every node the batch would create below it from the pending-node map,
     * so a later anchor on one of their refs is refused as undefined instead of resolving to a node
     * the batch no longer creates.
     */
    private function forgetPendingNodesBelow(NodeDescriptor $node): void
    {
        $deletedRef = $node->getRef();
        $deletedStoredNodeId = $node->getStoredNode()?->aggregateId->value;
        if ($deletedRef !== null) {
            unset($this->pendingNodes[$deletedRef]);
        }

        foreach ($this->pendingNodes as $ref => $pendingNode) {
            for ($ancestor = $pendingNode->getPendingParent(); $ancestor !== null; $ancestor = $ancestor->getPendingParent()) {
                $isBelowDeletedNode = ($deletedRef !== null && $ancestor->getRef() === $deletedRef)
                    || ($deletedStoredNodeId !== null && $ancestor->getStoredNode()?->aggregateId->value === $deletedStoredNodeId);
                if ($isBelowDeletedNode) {
                    unset($this->pendingNodes[$ref]);
                    break;
                }
            }
        }
    }

    /**
     * Get a NodeType by name, throwing PatchFailedException if not found.
     *
     * @param string $nodeTypeName
     * @param int $patchIndex
     * @param string $operation
     * @param ContentRepository $contentRepository
     * @return NodeType
     * @throws PatchFailedException
     */
    private function getNodeType(string $nodeTypeName, int $patchIndex, string $operation, ContentRepository $contentRepository): NodeType
    {
        $nodeType = $this->findNodeType($nodeTypeName, $contentRepository);
        if ($nodeType === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" does not exist. Hint: Check the TypeScript node type definitions in your system prompt', $nodeTypeName),
                $patchIndex,
                $operation
            );
        }

        return $nodeType;
    }

    /**
     * Check with hasNodeType() first, because getNodeType() returns a FallbackNode instead of null
     * when a fallback NodeType is configured.
     */
    private function findNodeType(string $nodeTypeName, ContentRepository $contentRepository): ?NodeType
    {
        $nodeTypeManager = $contentRepository->getNodeTypeManager();

        return $nodeTypeManager->hasNodeType($nodeTypeName) ? $nodeTypeManager->getNodeType($nodeTypeName) : null;
    }

    /**
     * The type of an anchored node, which a pending node always has; a stored node of a type unknown
     * to the schema fails like a createNode of an unknown type.
     *
     * @throws PatchFailedException
     */
    private function requireKnownNodeType(NodeDescriptor $node, int $patchIndex, string $operation): NodeType
    {
        $nodeType = $node->getNodeType();
        if ($nodeType === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" does not exist. Hint: Check the TypeScript node type definitions in your system prompt', $node->getStoredNode()?->nodeTypeName->value),
                $patchIndex,
                $operation
            );
        }

        return $nodeType;
    }

    /**
     * Resolve an anchor field (`positionRelativeToNodeId`, `nodeId`, `targetNodeId`): a stored node id, a
     * pending `$<ref>` declared by an earlier createNode patch, or one auto-created child `$<ref>/<childName>`.
     *
     * @throws PatchFailedException
     */
    private function resolveAnchor(string $anchor, string $field, int $patchIndex, string $operation, ContentSubgraphInterface $subgraph, ContentRepository $contentRepository): NodeDescriptor
    {
        try {
            $refAnchor = RefAnchor::fromAnchor($anchor);
        } catch (\InvalidArgumentException $e) {
            throw new PatchFailedException(
                sprintf(
                    'Malformed reference "%s" in "%s" at patch %d: %s. Use "$<ref>" for a node created earlier '
                    . 'in this batch, or "$<ref>/<childName>" for one of its auto-created child nodes.',
                    $anchor,
                    $field,
                    $patchIndex,
                    $e->getMessage()
                ),
                $patchIndex,
                $operation,
                $anchor
            );
        }

        if ($refAnchor === null) {
            $node = $this->getNodeById($anchor, $patchIndex, $operation, $subgraph);
            if (isset($this->deletedStoredNodeIds[$node->aggregateId->value])) {
                throw new PatchFailedException(
                    sprintf(
                        'Node "%s" in "%s" at patch %d was deleted by patch %d of this batch and cannot be addressed afterwards.',
                        $anchor,
                        $field,
                        $patchIndex,
                        $this->deletedStoredNodeIds[$node->aggregateId->value]
                    ),
                    $patchIndex,
                    $operation,
                    $anchor
                );
            }

            return NodeDescriptor::forStoredNode(
                $node,
                $this->findNodeType($node->nodeTypeName->value, $contentRepository),
                $subgraph,
                $contentRepository->getNodeTypeManager()
            );
        }

        $pendingNode = $this->pendingNodes[$refAnchor->getRef()] ?? null;
        if ($pendingNode === null) {
            $declaredRefs = array_keys($this->pendingNodes);
            throw new PatchFailedException(
                sprintf(
                    'Undefined reference "%s" in "%s" at patch %d: no earlier createNode patch declares "ref": "%s". %s',
                    $anchor,
                    $field,
                    $patchIndex,
                    $refAnchor->getRef(),
                    $declaredRefs === []
                        ? sprintf(
                            'No refs are declared before patch %d; add "ref" to the createNode patch that creates '
                            . 'this node and place it earlier in the batch.',
                            $patchIndex
                        )
                        : sprintf('Refs declared before patch %d: %s.', $patchIndex, $this->quoteList($declaredRefs))
                ),
                $patchIndex,
                $operation,
                $anchor
            );
        }

        $childName = $refAnchor->getChildName();
        if ($childName === null) {
            return $pendingNode;
        }

        $childNodeName = $this->tryNodeName($childName);
        $childNodeType = $childNodeName === null ? null : $pendingNode->getAutoCreatedChildNodeType($childNodeName);
        if ($childNodeName === null || $childNodeType === null) {
            $childNames = $pendingNode->getAutoCreatedChildNames();
            throw new PatchFailedException(
                sprintf(
                    'Unknown child "%s" in "%s" at patch %d: node type "%s" (ref "%s") '
                    . 'has no auto-created child node named "%s". %s',
                    $anchor,
                    $field,
                    $patchIndex,
                    $pendingNode->getNodeType()?->name->value,
                    $refAnchor->getRef(),
                    $childName,
                    $childNames === []
                        ? sprintf('It has no auto-created child nodes; use "$%s" to address the node itself.', $refAnchor->getRef())
                        : sprintf('Valid child names: %s.', $this->quoteList($childNames))
                ),
                $patchIndex,
                $operation,
                $anchor
            );
        }

        return NodeDescriptor::forAutoCreatedChildOfPendingNode($anchor, $childNodeName, $childNodeType, $pendingNode);
    }

    /**
     * The parent an anchored node has (a stored node's from the subgraph, a pending node's as declared or
     * moved within the batch); null for a stored node without a parent.
     */
    private function resolveParent(NodeDescriptor $node, ContentSubgraphInterface $subgraph, ContentRepository $contentRepository): ?NodeDescriptor
    {
        $storedNode = $node->getStoredNode();
        if ($storedNode === null) {
            return $node->getPendingParent();
        }
        if (isset($this->movedNodeParents[$storedNode->aggregateId->value])) {
            return $this->movedNodeParents[$storedNode->aggregateId->value];
        }

        $parentNode = $subgraph->findParentNode($storedNode->aggregateId);

        return $parentNode === null
            ? null
            : NodeDescriptor::forStoredNode(
                $parentNode,
                $this->findNodeType($parentNode->nodeTypeName->value, $contentRepository),
                $subgraph,
                $contentRepository->getNodeTypeManager()
            );
    }

    /**
     * Child names that cannot be node names (Neos 9 names are lowercase letters, digits and dashes) name
     * no auto-created child.
     */
    private function tryNodeName(string $name): ?NodeName
    {
        try {
            return NodeName::fromString($name);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Get a node by identifier, throwing PatchFailedException if not found.
     *
     * Accepts both a plain NodeAggregateId string and a NodeAddress JSON string
     * (as emitted by the read API via NodeAddress::toJson()).
     *
     * @param string $nodeId
     * @param int $patchIndex
     * @param string $operation
     * @param ContentSubgraphInterface $subgraph
     * @return Node
     * @throws PatchFailedException
     */
    private function getNodeById(string $nodeId, int $patchIndex, string $operation, ContentSubgraphInterface $subgraph): Node
    {
        try {
            $nodeAggregateId = str_starts_with(ltrim($nodeId), '{')
                ? NodeAddress::fromJsonString($nodeId)->aggregateId
                : NodeAggregateId::fromString($nodeId);
        } catch (\Throwable $e) {
            throw new PatchFailedException(
                sprintf('Node identifier "%s" is not a valid node aggregate id or node address', $nodeId),
                $patchIndex,
                $operation,
                $nodeId,
                $e
            );
        }
        $node = $subgraph->findNodeById($nodeAggregateId);
        if ($node === null) {
            throw new PatchFailedException(
                sprintf(
                    'Node with identifier "%s" does not exist. Use the id of an existing node, '
                    . 'or "$<ref>" to address a node created by an earlier createNode patch of this batch.',
                    $nodeId
                ),
                $patchIndex,
                $operation,
                $nodeId
            );
        }
        return $node;
    }

    /**
     * Message for a node type that the parent forbids, listing what the parent does allow.
     *
     * @param NodeDescriptor|null $movedNode The moved node, when the check is for a moveNode patch
     */
    private function buildChildConstraintMessage(NodeType $nodeType, NodeDescriptor $parent, ContentRepository $contentRepository, ?NodeDescriptor $movedNode = null): string
    {
        $allowedNames = $parent->allowedChildNodeTypeNames($contentRepository->getNodeTypeManager()->getNodeTypes(false));

        return sprintf(
            'NodeType "%s"%s is not allowed as child of %s (%s). %s',
            $nodeType->name->value,
            $movedNode === null ? '' : sprintf(' of %s', $movedNode->getLabel()),
            $parent->getLabel(),
            $parent->getNodeType()?->name->value,
            $allowedNames === []
                ? 'It allows no child nodes at all; choose a different parent.'
                : sprintf('Allowed child types: %s.', $this->quoteList($allowedNames))
        );
    }

    /**
     * Quote and join names for a message, capped at MAX_LISTED_NODE_TYPES entries.
     *
     * @param array<int, string> $names
     */
    private function quoteList(array $names): string
    {
        $quoted = array_map(static fn(string $name): string => '"' . $name . '"', array_slice($names, 0, self::MAX_LISTED_NODE_TYPES));
        if (count($names) > self::MAX_LISTED_NODE_TYPES) {
            $quoted[] = sprintf('… (%d more)', count($names) - self::MAX_LISTED_NODE_TYPES);
        }

        return implode(', ', $quoted);
    }

    /**
     * Validate the position parameter.
     *
     * @param string $position
     * @param int $patchIndex
     * @param string $operation
     * @param string|null $nodeId
     * @throws PatchFailedException
     */
    private function validatePosition(string $position, int $patchIndex, string $operation, ?string $nodeId): void
    {
        $validPositions = ['into', 'before', 'after'];
        if (!in_array($position, $validPositions, true)) {
            throw new PatchFailedException(
                sprintf(
                    'Invalid position "%s", must be one of: %s',
                    $position,
                    implode(', ', $validPositions)
                ),
                $patchIndex,
                $operation,
                $nodeId
            );
        }
    }

    /**
     * Validate properties using the PropertiesProcessor from NodeTemplates.
     *
     * Properties are normalized before validation to convert asset objects
     * (with 'identifier' key) to plain identifier strings.
     *
     * @param array<string, mixed> $properties
     * @param NodeType $nodeType
     * @param int $patchIndex
     * @param string $operation
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateProperties(array $properties, NodeType $nodeType, int $patchIndex, string $operation, ContentSubgraphInterface $subgraph): void
    {
        // Internal properties like "_hidden" are handled as node state (disable/enable), not as schema properties
        $properties = array_filter($properties, static fn($propertyName) => !str_starts_with((string)$propertyName, '_'), ARRAY_FILTER_USE_KEY);
        if (empty($properties)) {
            return;
        }

        // Normalize properties before validation
        // This converts asset objects (with 'identifier' key) to plain identifier strings
        $normalizedProperties = $this->propertyNormalizer->normalizeProperties($properties, $nodeType);

        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());
        $nodeTypeManager = $contentRepository->getNodeTypeManager();

        // Create a transient node to validate properties; the aggregate id is a throw-away placeholder
        $transientNode = TransientNode::forRegular(
            NodeAggregateId::create(),
            $subgraph->getWorkspaceName(),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($subgraph->getDimensionSpacePoint()),
            $nodeType,
            NodeAggregateIdsByNodePaths::createForNodeType($nodeType->name, $nodeTypeManager),
            $nodeTypeManager,
            $subgraph,
            $normalizedProperties
        );

        $processingErrors = ProcessingErrors::create();
        // Use PropertiesProcessor to validate and process properties; reference-type
        // entries are split off by the TransientNode and validated separately
        $this->propertiesProcessor->processAndValidateProperties($transientNode, $processingErrors);
        $this->referencesProcessor->processAndValidateReferences($transientNode, $processingErrors);

        // Check for validation errors
        if ($processingErrors->hasError()) {
            $firstError = $processingErrors->first();
            if ($firstError !== null) {
                throw new PatchFailedException(
                    $firstError->toMessage(),
                    $patchIndex,
                    $operation
                );
            }
        }
    }
}

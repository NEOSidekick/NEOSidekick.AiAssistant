<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrors;
use Flowpack\NodeTemplates\Domain\NodeCreation\PropertiesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\TransientNode;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreateNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreateVariantPatch;
use NEOSidekick\AiAssistant\Dto\Patch\DeleteNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\MoveNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\RefAnchor;
use NEOSidekick\AiAssistant\Dto\Patch\UpdateNodePatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PatchValidation\NodeDescriptor;
use NEOSidekick\AiAssistant\Service\PropertyNormalizer;

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
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var PropertiesProcessor
     */
    protected $propertiesProcessor;

    /**
     * @Flow\Inject
     * @var PropertyNormalizer
     */
    protected $propertyNormalizer;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * Nodes declared with `ref` by the createNode patches validated so far, keyed by ref.
     *
     * @var array<string, NodeDescriptor>
     */
    private array $pendingNodes = [];

    /**
     * Validate a whole batch before execution, in patch order, stopping at the first error.
     *
     * @param array<int, AbstractPatch> $patches
     * @throws PatchFailedException If validation fails
     */
    public function validatePatches(array $patches, Context $context): void
    {
        $this->pendingNodes = [];
        try {
            $this->refuseCreateVariantMixedWithOtherOperations($patches);
            $this->refuseChangesAffectingOtherDimensions($patches, $context);
            foreach ($patches as $patchIndex => $patch) {
                $this->validatePatch($patch, $patchIndex, $context);
            }
        } finally {
            $this->pendingNodes = [];
        }
    }

    /**
     * A new variant's nodes do not exist while the batch is validated, so patches changing them
     * cannot be checked in the same batch.
     *
     * @param array<int, AbstractPatch> $patches
     * @throws PatchFailedException
     */
    private function refuseCreateVariantMixedWithOtherOperations(array $patches): void
    {
        $createVariantIndex = null;
        $otherIndex = null;
        foreach ($patches as $patchIndex => $patch) {
            if ($patch instanceof CreateVariantPatch) {
                $createVariantIndex ??= $patchIndex;
            } else {
                $otherIndex ??= $patchIndex;
            }
        }

        if ($createVariantIndex !== null && $otherIndex !== null) {
            throw new PatchFailedException(
                'createVariant cannot be combined with other operations in one batch. Create the variants first, '
                . 'then read the new variant and change it in a following batch.',
                $createVariantIndex,
                'createVariant',
                $patches[$createVariantIndex]->getNodeId()
            );
        }
    }

    /**
     * Refuse the batch when it changes stored nodes in a way the legacy content repository carries
     * over into other dimension variants. Variant creation and changes to other languages must
     * remain explicit editor decisions in the Neos UI:
     *
     * - A node the requested dimensions only show through a dimension fallback: an update or a
     *   reorder materializes a variant, a move to another parent relocates the node of the dimension
     *   it is shown from, and a delete leaves a removed shadow that publishing drops.
     * - Deleting a node's own variant while the node also exists in a fallback value: publishing
     *   removes only this variant, and the fallback content shows again.
     * - Moving a content node to another parent while it contains fallback nodes: those children are
     *   moved in the dimension they are shown from.
     *
     * All such nodes of the batch are named at once, so the batch can be resent without them.
     *
     * @param array<int, AbstractPatch> $patches
     * @throws PatchFailedException
     */
    private function refuseChangesAffectingOtherDimensions(array $patches, Context $context): void
    {
        $refusals = [];
        $firstRefusal = null;
        foreach ($patches as $patchIndex => $patch) {
            if (!$patch instanceof UpdateNodePatch && !$patch instanceof MoveNodePatch && !$patch instanceof DeleteNodePatch) {
                continue;
            }

            // `$<ref>` anchors address nodes created by this batch, which exist only in these dimensions
            $nodeId = $patch->getNodeId();
            if (RefAnchor::isAnchor($nodeId) || isset($refusals[$nodeId])) {
                continue;
            }

            $node = $context->getNodeByIdentifier($nodeId);
            $reason = $node === null ? null : $this->describeEffectOnOtherDimensions($patch, $node, $context);
            if ($reason === null) {
                continue;
            }

            $refusals[$nodeId] = sprintf('"%s" %s', $nodeId, $reason);
            $firstRefusal ??= [$patchIndex, $patch->getOperation(), $nodeId];
        }

        if ($firstRefusal === null) {
            return;
        }

        throw new PatchFailedException(
            sprintf(
                'Not applied, because these changes would affect other content dimensions: %s. '
                . 'Resend the batch without them.',
                implode('; ', $refusals)
            ),
            $firstRefusal[0],
            $firstRefusal[1],
            $firstRefusal[2]
        );
    }

    private function describeEffectOnOtherDimensions(AbstractPatch $patch, NodeInterface $node, Context $context): ?string
    {
        if (DimensionFallbackDetector::isDimensionFallback($node)) {
            return sprintf(
                'is a dimension fallback shown from %s and cannot be updated, moved or deleted here. To give it its own '
                . 'content in these dimensions, the editor must create the variant in the Neos UI first',
                $this->describeDimensionValues($node->getNodeData()->getDimensionValues())
            );
        }

        if ($patch instanceof DeleteNodePatch) {
            $fallbackDimensions = $this->findFallbackDimensionsContainingNode($node, $context);
            if ($fallbackDimensions !== null) {
                return sprintf(
                    'also exists in %1$s: deleting it here would only remove this variant, and the %1$s content would '
                    . 'show again after publishing. Hide it instead by setting "_hidden" to true',
                    $fallbackDimensions
                );
            }
        }

        if ($patch instanceof MoveNodePatch && !$node->getNodeType()->isOfType('Neos.Neos:Document') && $this->movesToAnotherParent($patch, $node, $context)) {
            $fallbackDescendant = $this->findDimensionFallbackDescendant($node);
            if ($fallbackDescendant !== null) {
                return sprintf(
                    'contains dimension fallbacks such as "%s", shown from %s, which would be moved there as well',
                    $fallbackDescendant->getIdentifier(),
                    $this->describeDimensionValues($fallbackDescendant->getNodeData()->getDimensionValues())
                );
            }
        }

        return null;
    }

    /**
     * The fallback values (e.g. `language=de` for the chain de_CH, de) in which the node exists as well.
     */
    private function findFallbackDimensionsContainingNode(NodeInterface $node, Context $context): ?string
    {
        foreach ($context->getDimensions() as $dimensionName => $values) {
            if (count($values) < 2) {
                continue;
            }

            $fallbackDimensions = array_merge($context->getDimensions(), [$dimensionName => array_slice($values, 1)]);
            $fallbackContext = $this->contextFactory->create([
                'workspaceName' => $context->getWorkspaceName(),
                'dimensions' => $fallbackDimensions,
                'targetDimensions' => array_map(static fn(array $chain): string => (string)reset($chain), $fallbackDimensions),
                'invisibleContentShown' => true,
                'removedContentShown' => false,
                'inaccessibleContentShown' => true,
            ]);
            // A legacy variant that stores the whole chain is found here as well; deleting it removes it
            // from every value of the chain, so only a different variant counts as fallback content.
            $fallbackNode = $fallbackContext->getNodeByIdentifier($node->getIdentifier());
            if ($fallbackNode !== null && $fallbackNode->getNodeData()->getDimensionsHash() !== $node->getNodeData()->getDimensionsHash()) {
                return $this->describeDimensionValues($fallbackNode->getNodeData()->getDimensionValues());
            }
        }

        return null;
    }

    /**
     * Same-parent reorders keep the children in place; only a new parent moves them.
     */
    private function movesToAnotherParent(MoveNodePatch $patch, NodeInterface $node, Context $context): bool
    {
        $targetNodeId = $patch->getTargetNodeId();
        if (RefAnchor::isAnchor($targetNodeId)) {
            return true;
        }

        $targetNode = $context->getNodeByIdentifier($targetNodeId);
        $newParent = $patch->getPosition() === 'into' ? $targetNode : $targetNode?->getParent();

        return $newParent === null || $newParent->getIdentifier() !== $node->getParent()?->getIdentifier();
    }

    private function findDimensionFallbackDescendant(NodeInterface $node): ?NodeInterface
    {
        $pendingNodes = $node->getChildNodes();
        while ($pendingNodes !== []) {
            $childNode = array_shift($pendingNodes);
            if (DimensionFallbackDetector::isDimensionFallback($childNode)) {
                return $childNode;
            }
            array_push($pendingNodes, ...$childNode->getChildNodes());
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $dimensionValues
     */
    private function describeDimensionValues(array $dimensionValues): string
    {
        $parts = [];
        foreach ($dimensionValues as $dimensionName => $values) {
            $parts[] = $dimensionName . '=' . implode(',', $values);
        }

        return implode(' and ', $parts);
    }

    /**
     * Validate a patch before execution.
     *
     * @param AbstractPatch $patch The patch to validate
     * @param int $patchIndex The index of the patch in the batch
     * @param Context $context The content context
     * @throws PatchFailedException If validation fails
     */
    private function validatePatch(AbstractPatch $patch, int $patchIndex, Context $context): void
    {
        $this->validateRefDeclaration($patch, $patchIndex);

        if ($patch instanceof CreateNodePatch) {
            $this->validateCreateNodePatch($patch, $patchIndex, $context);
        } elseif ($patch instanceof UpdateNodePatch) {
            $this->validateUpdateNodePatch($patch, $patchIndex, $context);
        } elseif ($patch instanceof MoveNodePatch) {
            $this->validateMoveNodePatch($patch, $patchIndex, $context);
        } elseif ($patch instanceof DeleteNodePatch) {
            $this->validateDeleteNodePatch($patch, $patchIndex, $context);
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

        if (isset($this->pendingNodes[$ref])) {
            throw new PatchFailedException(
                sprintf(
                    'Duplicate ref "%s" at patch %d: it was already declared by patch %d. '
                    . 'Refs must be unique within the batch; rename one of them.',
                    $ref,
                    $patchIndex,
                    $this->pendingNodes[$ref]->getDeclaredAtPatchIndex()
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
     * @param Context $context
     * @throws PatchFailedException
     */
    private function validateCreateNodePatch(CreateNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate nodeType exists
        $nodeType = $this->getNodeType($patch->getNodeType(), $patchIndex, 'createNode');

        // Validate nodeType is not abstract (abstract types cannot be instantiated)
        if ($nodeType->isAbstract()) {
            throw new PatchFailedException(
                sprintf('Cannot create node of abstract NodeType "%s"', $nodeType->getName()),
                $patchIndex,
                'createNode'
            );
        }

        // Validate reference node exists (parent for 'into', sibling for 'before'/'after')
        $referenceNode = $this->resolveAnchor($patch->getPositionRelativeToNodeId(), 'positionRelativeToNodeId', $patchIndex, 'createNode', $context);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'createNode', $patch->getPositionRelativeToNodeId());

        // Determine actual parent based on position
        // For 'into': the referenceNode is the parent
        // For 'before'/'after': the referenceNode is a sibling, so the actual parent is its parent
        if ($patch->getPosition() === 'into') {
            $actualParent = $referenceNode;
        } else {
            $actualParent = $referenceNode->getParent();
            if ($actualParent === null) {
                throw new PatchFailedException(
                    sprintf('Reference node "%s" has no parent', $patch->getPositionRelativeToNodeId()),
                    $patchIndex,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }
        }

        // Validate node type is allowed as child of the actual parent
        if (!$actualParent->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                $this->buildChildConstraintMessage($nodeType, $actualParent),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'createNode', $context);

        if ($patch->getRef() !== null) {
            $this->pendingNodes[$patch->getRef()] = NodeDescriptor::forPendingNode($patch->getRef(), $patchIndex, $nodeType, $actualParent);
        }
    }

    /**
     * Validate an updateNode patch.
     *
     * @param UpdateNodePatch $patch
     * @param int $patchIndex
     * @param Context $context
     * @throws PatchFailedException
     */
    private function validateUpdateNodePatch(UpdateNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'updateNode', $context);

        // `_hidden` is the node's visibility flag, not a property declared in the NodeType
        $properties = $patch->getProperties();
        if (array_key_exists('_hidden', $properties)) {
            if (!is_bool($properties['_hidden'])) {
                throw new PatchFailedException('Property "_hidden" must be true or false.', $patchIndex, 'updateNode', $patch->getNodeId());
            }
            unset($properties['_hidden']);
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($properties, $node->getNodeType(), $patchIndex, 'updateNode', $context);
    }

    /**
     * Validate a moveNode patch.
     *
     * @param MoveNodePatch $patch
     * @param int $patchIndex
     * @param Context $context
     * @throws PatchFailedException
     */
    private function validateMoveNodePatch(MoveNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate source node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'moveNode', $context);

        // Validate target node exists
        $targetNode = $this->resolveAnchor($patch->getTargetNodeId(), 'targetNodeId', $patchIndex, 'moveNode', $context);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'moveNode', $patch->getNodeId());

        // Determine the new parent node based on position
        if ($patch->getPosition() === 'into') {
            $newParentNode = $targetNode;
        } else {
            // For 'before' or 'after', parent will be target's parent
            $newParentNode = $targetNode->getParent();
            if ($newParentNode === null) {
                throw new PatchFailedException(
                    sprintf('Target node "%s" has no parent node', $patch->getTargetNodeId()),
                    $patchIndex,
                    'moveNode',
                    $patch->getNodeId()
                );
            }
        }

        // Validate node type constraints in the new location
        if (!$newParentNode->allowsChildNodeType($node->getNodeType())) {
            throw new PatchFailedException(
                $this->buildChildConstraintMessage($node->getNodeType(), $newParentNode, $node),
                $patchIndex,
                'moveNode',
                $patch->getNodeId()
            );
        }

        // A moved pending node reports its new parent to later before/after anchors
        if ($node->isPendingNode()) {
            $this->pendingNodes[$node->getRef()] = $node->withParent($newParentNode);
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
     * @param Context $context
     * @throws PatchFailedException
     */
    private function validateDeleteNodePatch(DeleteNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate node exists
        $node = $this->resolveAnchor($patch->getNodeId(), 'nodeId', $patchIndex, 'deleteNode', $context);

        // A deleted pending node stops being addressable: later anchors on its ref are undefined
        if ($node->isPendingNode()) {
            unset($this->pendingNodes[$node->getRef()]);
        }
    }

    /**
     * Get a NodeType by name, throwing PatchFailedException if not found.
     *
     * @param string $nodeTypeName
     * @param int $patchIndex
     * @param string $operation
     * @return NodeType
     * @throws PatchFailedException
     */
    private function getNodeType(string $nodeTypeName, int $patchIndex, string $operation): NodeType
    {
        // Check with hasNodeType() first, because getNodeType() returns a
        // FallbackNode instead of throwing when a fallback NodeType is configured.
        if (!$this->nodeTypeManager->hasNodeType($nodeTypeName)) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" does not exist. Hint: Check the TypeScript node type definitions in your system prompt', $nodeTypeName),
                $patchIndex,
                $operation
            );
        }

        return $this->nodeTypeManager->getNodeType($nodeTypeName);
    }

    /**
     * Resolve an anchor field (`positionRelativeToNodeId`, `nodeId`, `targetNodeId`): a stored node id, a
     * pending `$<ref>` declared by an earlier createNode patch, or one auto-created child `$<ref>/<childName>`.
     *
     * @throws PatchFailedException
     */
    private function resolveAnchor(string $anchor, string $field, int $patchIndex, string $operation, Context $context): NodeDescriptor
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
            return NodeDescriptor::forStoredNode($this->getNodeById($anchor, $patchIndex, $operation, $context));
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

        $childNodeType = $pendingNode->getAutoCreatedChildNodeType($childName);
        if ($childNodeType === null) {
            $childNames = $pendingNode->getAutoCreatedChildNames();
            throw new PatchFailedException(
                sprintf(
                    'Unknown child "%s" in "%s" at patch %d: node type "%s" (ref "%s") '
                    . 'has no auto-created child node named "%s". %s',
                    $anchor,
                    $field,
                    $patchIndex,
                    $pendingNode->getNodeType()->getName(),
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

        return NodeDescriptor::forAutoCreatedChildOfPendingNode($anchor, $childName, $childNodeType, $pendingNode);
    }

    /**
     * Get a node by identifier, throwing PatchFailedException if not found.
     *
     * @param string $nodeId
     * @param int $patchIndex
     * @param string $operation
     * @param Context $context
     * @return NodeInterface
     * @throws PatchFailedException
     */
    private function getNodeById(string $nodeId, int $patchIndex, string $operation, Context $context): NodeInterface
    {
        $node = $context->getNodeByIdentifier($nodeId);
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
    private function buildChildConstraintMessage(NodeType $nodeType, NodeDescriptor $parent, ?NodeDescriptor $movedNode = null): string
    {
        $allowedNames = $parent->allowedChildNodeTypeNames($this->nodeTypeManager->getNodeTypes(false));

        return sprintf(
            'NodeType "%s"%s is not allowed as child of %s (%s). %s',
            $nodeType->getName(),
            $movedNode === null ? '' : sprintf(' of %s', $movedNode->getLabel()),
            $parent->getLabel(),
            $parent->getNodeType()->getName(),
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
     * @param Context $context
     * @throws PatchFailedException
     */
    private function validateProperties(array $properties, NodeType $nodeType, int $patchIndex, string $operation, Context $context): void
    {
        if (empty($properties)) {
            return;
        }

        // Normalize properties before validation
        // This converts asset objects (with 'identifier' key) to plain identifier strings
        $normalizedProperties = $this->propertyNormalizer->normalizeProperties($properties, $nodeType);

        $processingErrors = ProcessingErrors::create();

        // Create a transient node to validate properties
        $transientNode = TransientNode::forRegular(
            $nodeType,
            $this->nodeTypeManager,
            $context,
            $normalizedProperties
        );

        // Use PropertiesProcessor to validate and process properties
        $this->propertiesProcessor->processAndValidateProperties($transientNode, $processingErrors);

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

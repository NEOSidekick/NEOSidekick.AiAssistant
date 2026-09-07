<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrors;
use Flowpack\NodeTemplates\Domain\NodeCreation\PropertiesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\TransientNode;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreateNodePatch;
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
            foreach ($patches as $patchIndex => $patch) {
                $this->validatePatch($patch, $patchIndex, $context);
            }
        } finally {
            $this->pendingNodes = [];
        }
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

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $node->getNodeType(), $patchIndex, 'updateNode', $context);
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

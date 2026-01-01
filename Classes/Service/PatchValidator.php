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
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreateNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\DeleteNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\MoveNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\UpdateNodePatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PropertyNormalizer;

/**
 * Validates patches before execution using NodeTemplates' PropertiesProcessor.
 *
 * This service validates properties against NodeType schemas before applying
 * any changes to the content repository, ensuring that invalid patches are
 * caught early without affecting the database.
 */
class PatchValidator
{
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
         * Validate a patch according to its specific type before execution.
         *
         * Delegates validation to the appropriate type-specific validator (create, update, move, delete).
         *
         * @param AbstractPatch $patch The patch to validate.
         * @param int $patchIndex The index of the patch in the batch, used for contextual error messages.
         * @param Context $context The content context used to resolve nodes and types.
         * @throws PatchFailedException If validation fails for any reason.
         */
    public function validatePatch(AbstractPatch $patch, int $patchIndex, Context $context): void
    {
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
     * Validate a create-node patch's target node type, reference node, position, parent-child constraints, and properties.
     *
     * For position "into" the reference node is treated as the parent; for "before" and "after" the reference node is treated as a sibling and the new node's parent is the sibling's parent.
     *
     * @param CreateNodePatch $patch The create-node patch to validate.
     * @param int $patchIndex Numeric index of the patch used for contextual error reporting.
     * @param Context $context Context used to resolve referenced nodes.
     * @throws PatchFailedException If the reference node is missing, the position is invalid, the computed parent disallows the node type, or property validation fails.
     * @throws NodeTypeNotFoundException If the referenced node type does not exist.
     */
    private function validateCreateNodePatch(CreateNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate nodeType exists
        $nodeType = $this->getNodeType($patch->getNodeType(), $patchIndex, 'createNode');

        // Validate reference node exists (parent for 'into', sibling for 'before'/'after')
        $referenceNode = $this->getNodeById($patch->getPositionRelativeToNodeId(), $patchIndex, 'createNode', $context);

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
        if (!$actualParent->getNodeType()->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of parent node type "%s"',
                    $nodeType->getName(),
                    $actualParent->getNodeType()->getName()
                ),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'createNode', $context);
    }

    /**
     * Validate that the target node exists and that the patch's properties are valid for the node's type.
     *
     * @param UpdateNodePatch $patch The update patch to validate.
     * @param int $patchIndex The index of the patch in the batch, used for error context.
     * @param Context $context Execution context used to resolve nodes.
     * @throws PatchFailedException If the target node is not found or property validation fails.
     */
    private function validateUpdateNodePatch(UpdateNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate node exists
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'updateNode', $context);

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $node->getNodeType(), $patchIndex, 'updateNode', $context);
    }

    /**
     * Validate that a MoveNodePatch can be applied.
     *
     * Ensures the source and target nodes exist, the requested position is one of "into", "before", or "after",
     * determines the effective new parent for the moved node, and verifies the new parent allows the moved node's type.
     *
     * @param MoveNodePatch $patch The move patch to validate (must contain nodeId, targetNodeId, and position).
     * @param int $patchIndex The index of the patch used for contextual error reporting.
     * @param Context $context Context used to resolve node identifiers.
     * @throws PatchFailedException If validation fails (missing nodes, invalid position, missing parent for positional move, or disallowed child node type).
     */
    private function validateMoveNodePatch(MoveNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate source node exists
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'moveNode', $context);

        // Validate target node exists
        $targetNode = $this->getNodeById($patch->getTargetNodeId(), $patchIndex, 'moveNode', $context);

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
        if (!$newParentNode->getNodeType()->allowsChildNodeType($node->getNodeType())) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of "%s"',
                    $node->getNodeType()->getName(),
                    $newParentNode->getNodeType()->getName()
                ),
                $patchIndex,
                'moveNode',
                $patch->getNodeId()
            );
        }
    }

    /**
     * Ensure the node referenced by a deleteNode patch exists.
     *
     * @throws PatchFailedException If the target node cannot be found.
     */
    private function validateDeleteNodePatch(DeleteNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate node exists
        $this->getNodeById($patch->getNodeId(), $patchIndex, 'deleteNode', $context);
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
        try {
            $nodeType = $this->nodeTypeManager->getNodeType($nodeTypeName);
            if ($nodeType === null) {
                throw new PatchFailedException(
                    sprintf('NodeType "%s" does not exist', $nodeTypeName),
                    $patchIndex,
                    $operation
                );
            }
            return $nodeType;
        } catch (NodeTypeNotFoundException $e) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" does not exist', $nodeTypeName),
                $patchIndex,
                $operation,
                null,
                $e
            );
        }
    }

    /**
     * Retrieve a node by its identifier.
     *
     * Throws PatchFailedException if the node cannot be resolved.
     *
     * @param string $nodeId The node identifier to resolve.
     * @param int $patchIndex Patch index used for error context.
     * @param string $operation Operation name used for error context.
     * @param Context $context Context used to resolve the node.
     * @return NodeInterface The resolved node.
     * @throws PatchFailedException If no node with the given identifier exists.
     */
    private function getNodeById(string $nodeId, int $patchIndex, string $operation, Context $context): NodeInterface
    {
        $node = $context->getNodeByIdentifier($nodeId);
        if ($node === null) {
            throw new PatchFailedException(
                sprintf('Node with identifier "%s" does not exist', $nodeId),
                $patchIndex,
                $operation,
                $nodeId
            );
        }
        return $node;
    }

    /**
     * Ensure the provided position is one of the allowed placement values for a node.
     *
     * @param string $position The placement value to validate; allowed values: "into", "before", "after".
     * @param int $patchIndex Index of the patch being validated (used for error context).
     * @param string $operation The patch operation name (used for error context).
     * @param string|null $nodeId Identifier of the reference node (used for error context).
     * @throws PatchFailedException If $position is not "into", "before", or "after".
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
     * Validates and processes property values for a given NodeType using NodeTemplates' PropertiesProcessor.
     *
     * Properties are first normalized (e.g., asset objects with an `identifier` key are converted to identifier strings),
     * then applied to a transient node and validated. If any processing errors are produced, a PatchFailedException is
     * thrown with the first error message and the provided patch context.
     *
     * @param array<string, mixed> $properties The property values to validate.
     * @param NodeType $nodeType The node type whose property definitions are used for validation.
     * @param int $patchIndex Index of the patch for error context.
     * @param string $operation Human-readable operation name for error context.
     * @param Context $context Execution context used to create the transient node.
     * @throws PatchFailedException When property processing yields validation errors; message contains the first error. 
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
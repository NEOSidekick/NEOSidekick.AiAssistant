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
     * Validate a patch before execution.
     *
     * @param AbstractPatch $patch The patch to validate
     * @param int $patchIndex The index of the patch in the batch
     * @param Context $context The content context
     * @throws PatchFailedException If validation fails
     * @throws NodeTypeNotFoundException If the node type doesn't exist
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
     * Validate a createNode patch.
     *
     * @param CreateNodePatch $patch
     * @param int $patchIndex
     * @param Context $context
     * @throws PatchFailedException
     * @throws NodeTypeNotFoundException
     */
    private function validateCreateNodePatch(CreateNodePatch $patch, int $patchIndex, Context $context): void
    {
        // Validate nodeType exists
        $nodeType = $this->getNodeType($patch->getNodeType(), $patchIndex, 'createNode');

        // Validate parent node exists
        $parentNode = $this->getNodeById($patch->getParentNodeId(), $patchIndex, 'createNode', $context);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'createNode', $patch->getParentNodeId());

        // Validate node type is allowed as child of parent
        if (!$parentNode->getNodeType()->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of parent node type "%s"',
                    $nodeType->getName(),
                    $parentNode->getNodeType()->getName()
                ),
                $patchIndex,
                'createNode',
                $patch->getParentNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'createNode', $context);
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
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'updateNode', $context);

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
     * Validate a deleteNode patch.
     *
     * @param DeleteNodePatch $patch
     * @param int $patchIndex
     * @param Context $context
     * @throws PatchFailedException
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
                sprintf('Node with identifier "%s" does not exist', $nodeId),
                $patchIndex,
                $operation,
                $nodeId
            );
        }
        return $node;
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

        $processingErrors = ProcessingErrors::create();

        // Create a transient node to validate properties
        $transientNode = TransientNode::forRegular(
            $nodeType,
            $this->nodeTypeManager,
            $context,
            $properties
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

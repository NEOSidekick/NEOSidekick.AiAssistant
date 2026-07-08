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
     * @var PropertiesProcessor
     */
    protected $propertiesProcessor;

    /**
     * @Flow\Inject
     * @var PropertyNormalizer
     */
    protected $propertyNormalizer;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Validate a patch before execution.
     *
     * @param AbstractPatch $patch The patch to validate
     * @param int $patchIndex The index of the patch in the batch
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context The content context
     * @throws PatchFailedException If validation fails
     */
    public function validatePatch(AbstractPatch $patch, int $patchIndex, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
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
     * For 'into' position, the positionRelativeToNodeId is the actual parent.
     * For 'before'/'after' positions, the positionRelativeToNodeId is a reference sibling node,
     * and the new node will be created under that sibling's parent.
     *
     * @param CreateNodePatch $patch
     * @param int $patchIndex
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @throws PatchFailedException
     */
    private function validateCreateNodePatch(CreateNodePatch $patch, int $patchIndex, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
    {
        // Validate nodeType exists
        $nodeType = $this->getNodeType($patch->getNodeType(), $patchIndex, 'createNode');

        // Validate nodeType is not abstract (abstract types cannot be instantiated)
        if ($nodeType->isAbstract()) {
            throw new PatchFailedException(
                sprintf('Cannot create node of abstract NodeType "%s"', $nodeType->name->value),
                $patchIndex,
                'createNode'
            );
        }

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
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($referenceNode);
            $actualParent = $subgraph->findParentNode($referenceNode->aggregateId);
            if ($actualParent === null) {
                throw new PatchFailedException(
                    sprintf('Reference node "%s" has no parent', $patch->getPositionRelativeToNodeId()),
                    $patchIndex,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }
        }
        $contentRepository = $this->contentRepositoryRegistry->get($actualParent->contentRepositoryId);

        // Validate node type is allowed as child of the actual parent
        if (!$contentRepository->getNodeTypeManager()->getNodeType($actualParent->nodeTypeName)->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of parent node type "%s"',
                    $nodeType->name->value,
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
     * Validate an updateNode patch.
     *
     * @param UpdateNodePatch $patch
     * @param int $patchIndex
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @throws PatchFailedException
     */
    private function validateUpdateNodePatch(UpdateNodePatch $patch, int $patchIndex, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
    {
        // Validate node exists
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'updateNode', $context);
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName), $patchIndex, 'updateNode', $context);
    }

    /**
     * Validate a moveNode patch.
     *
     * @param MoveNodePatch $patch
     * @param int $patchIndex
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @throws PatchFailedException
     */
    private function validateMoveNodePatch(MoveNodePatch $patch, int $patchIndex, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
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
            $subgraph = $this->contentRepositoryRegistry->subgraphForNode($targetNode);
            // For 'before' or 'after', parent will be target's parent
            $newParentNode = $subgraph->findParentNode($targetNode->aggregateId);
            if ($newParentNode === null) {
                throw new PatchFailedException(
                    sprintf('Target node "%s" has no parent node', $patch->getTargetNodeId()),
                    $patchIndex,
                    'moveNode',
                    $patch->getNodeId()
                );
            }
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        // Validate node type constraints in the new location
        if (!$contentRepository->getNodeTypeManager()->getNodeType($newParentNode->nodeTypeName)->allowsChildNodeType($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName))) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of "%s"',
                    $node->nodeTypeName->value,
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
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @throws PatchFailedException
     */
    private function validateDeleteNodePatch(DeleteNodePatch $patch, int $patchIndex, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
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
     * @return \Neos\ContentRepository\Core\NodeType\NodeType
     * @throws PatchFailedException
     */
    private function getNodeType(string $nodeTypeName, int $patchIndex, string $operation): \Neos\ContentRepository\Core\NodeType\NodeType
    {
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        // Check with hasNodeType() first, because getNodeType() returns a
        // FallbackNode instead of throwing when a fallback NodeType is configured.
        if (!$contentRepository->getNodeTypeManager()->hasNodeType($nodeTypeName)) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" does not exist. Hint: Check the TypeScript node type definitions in your system prompt', $nodeTypeName),
                $patchIndex,
                $operation
            );
        }
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));

        return $contentRepository->getNodeTypeManager()->getNodeType($nodeTypeName);
    }

    /**
     * Get a node by identifier, throwing PatchFailedException if not found.
     *
     * @param string $nodeId
     * @param int $patchIndex
     * @param string $operation
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     * @throws PatchFailedException
     */
    private function getNodeById(string $nodeId, int $patchIndex, string $operation, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): \Neos\ContentRepository\Core\Projection\ContentGraph\Node
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
     * Properties are normalized before validation to convert asset objects
     * (with 'identifier' key) to plain identifier strings.
     *
     * @param array<string, mixed> $properties
     * @param \Neos\ContentRepository\Core\NodeType\NodeType $nodeType
     * @param int $patchIndex
     * @param string $operation
     * @param \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context
     * @throws PatchFailedException
     */
    private function validateProperties(array $properties, \Neos\ContentRepository\Core\NodeType\NodeType $nodeType, int $patchIndex, string $operation, \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context): void
    {
        if (empty($properties)) {
            return;
        }

        // Normalize properties before validation
        // This converts asset objects (with 'identifier' key) to plain identifier strings
        $normalizedProperties = $this->propertyNormalizer->normalizeProperties($properties, $nodeType);

        $processingErrors = ProcessingErrors::create();
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));

        // Create a transient node to validate properties
        $transientNode = TransientNode::forRegular(
            $nodeType,
            $contentRepository->getNodeTypeManager(),
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

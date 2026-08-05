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
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
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
     * Validate a patch before execution.
     *
     * @param AbstractPatch $patch The patch to validate
     * @param int $patchIndex The index of the patch in the batch
     * @param ContentSubgraphInterface $subgraph The content subgraph (workspace + dimensions) to validate against
     * @throws PatchFailedException If validation fails
     */
    public function validatePatch(AbstractPatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
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
        $referenceNode = $this->getNodeById($patch->getPositionRelativeToNodeId(), $patchIndex, 'createNode', $subgraph);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'createNode', $patch->getPositionRelativeToNodeId());

        // Determine actual parent based on position
        // For 'into': the referenceNode is the parent
        // For 'before'/'after': the referenceNode is a sibling, so the actual parent is its parent
        if ($patch->getPosition() === 'into') {
            $actualParent = $referenceNode;
        } else {
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

        $parentNodeType = $contentRepository->getNodeTypeManager()->getNodeType($actualParent->nodeTypeName);
        if ($parentNodeType === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" of the parent node is not known to the schema', $actualParent->nodeTypeName->value),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate node type is allowed as child of the actual parent
        if (!$parentNodeType->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of parent node type "%s"',
                    $nodeType->name->value,
                    $actualParent->nodeTypeName->value
                ),
                $patchIndex,
                'createNode',
                $patch->getPositionRelativeToNodeId()
            );
        }

        // Validate properties using PropertiesProcessor
        $this->validateProperties($patch->getProperties(), $nodeType, $patchIndex, 'createNode', $subgraph);
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
        // Validate node exists
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'updateNode', $subgraph);
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());

        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            throw new PatchFailedException(
                sprintf('NodeType "%s" of node "%s" is not known to the schema', $node->nodeTypeName->value, $patch->getNodeId()),
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
        // Validate source node exists
        $node = $this->getNodeById($patch->getNodeId(), $patchIndex, 'moveNode', $subgraph);

        // Validate target node exists
        $targetNode = $this->getNodeById($patch->getTargetNodeId(), $patchIndex, 'moveNode', $subgraph);

        // Validate position
        $this->validatePosition($patch->getPosition(), $patchIndex, 'moveNode', $patch->getNodeId());

        // Determine the new parent node based on position
        if ($patch->getPosition() === 'into') {
            $newParentNode = $targetNode;
        } else {
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
        $contentRepository = $this->contentRepositoryRegistry->get($subgraph->getContentRepositoryId());
        // Unknown node types must fail here like in the create/update validations — a silent
        // skip would defer the failure to CR execution time, mid-batch and without rollback.
        $newParentNodeType = $this->getNodeType($newParentNode->nodeTypeName->value, $patchIndex, 'moveNode', $contentRepository);
        $nodeType = $this->getNodeType($node->nodeTypeName->value, $patchIndex, 'moveNode', $contentRepository);

        // Validate node type constraints in the new location
        if (!$newParentNodeType->allowsChildNodeType($nodeType)) {
            throw new PatchFailedException(
                sprintf(
                    'NodeType "%s" is not allowed as child of "%s"',
                    $node->nodeTypeName->value,
                    $newParentNode->nodeTypeName->value
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
     * @param ContentSubgraphInterface $subgraph
     * @throws PatchFailedException
     */
    private function validateDeleteNodePatch(DeleteNodePatch $patch, int $patchIndex, ContentSubgraphInterface $subgraph): void
    {
        // Validate node exists
        $this->getNodeById($patch->getNodeId(), $patchIndex, 'deleteNode', $subgraph);
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
        // Check with hasNodeType() first, because getNodeType() returns a
        // FallbackNode instead of null when a fallback NodeType is configured.
        $nodeTypeManager = $contentRepository->getNodeTypeManager();
        $nodeType = $nodeTypeManager->hasNodeType($nodeTypeName) ? $nodeTypeManager->getNodeType($nodeTypeName) : null;
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

<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use Flowpack\NodeTemplates\Domain\TemplateNodeCreationHandler;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Dto\Patch\CreatedNodeInfo;
use NEOSidekick\AiAssistant\Dto\Patch\CreateNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\DeleteNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\MoveNodePatch;
use NEOSidekick\AiAssistant\Dto\Patch\PatchError;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Dto\Patch\UpdateNodePatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;

/**
 * Service for applying patches to the content repository.
 *
 * Handles atomic patch operations with validation, rollback support,
 * and dry-run functionality using database transactions.
 */
class NodePatchService
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var PatchValidator
     */
    protected $patchValidator;

    /**
     * @Flow\Inject
     * @var TemplateNodeCreationHandler
     */
    protected $templateNodeCreationHandler;

    /**
     * @Flow\Inject
     * @var PropertyNormalizer
     */
    protected $propertyNormalizer;

    /**
     * Apply a batch of patches to the content repository.
     *
     * All patches are executed within a database transaction. If any patch
     * fails, all changes are rolled back. In dry-run mode, changes are
     * validated and then rolled back regardless of success.
     *
     * @param array<int, array<string, mixed>> $patchesData Raw patch data from API request
     * @param string $workspace The workspace name to apply patches in
     * @param array<string, array<int, string>> $dimensions Content dimensions
     * @param bool $dryRun If true, validate and rollback without persisting
     * @return PatchResult
     */
    public function applyPatches(array $patchesData, string $workspace, array $dimensions, bool $dryRun = false): PatchResult
    {
        // Parse patches from raw data
        $patches = [];
        foreach ($patchesData as $index => $patchData) {
            try {
                $patches[] = AbstractPatch::fromArray($patchData);
            } catch (\InvalidArgumentException $e) {
                return PatchResult::failure(
                    $dryRun,
                    new PatchError($e->getMessage(), $index, 'unknown'),
                    false
                );
            }
        }

        // Create content context for the workspace and dimensions
        $context = $this->createContext($workspace, $dimensions);

        // Pre-validate all patches before starting the transaction
        foreach ($patches as $index => $patch) {
            try {
                $this->patchValidator->validatePatch($patch, $index, $context);
            } catch (PatchFailedException $e) {
                return PatchResult::failure(
                    $dryRun,
                    new PatchError($e->getMessage(), $e->getPatchIndex(), $e->getOperation(), $e->getNodeId()),
                    false
                );
            } catch (NodeTypeNotFoundException $e) {
                return PatchResult::failure(
                    $dryRun,
                    new PatchError($e->getMessage(), $index, 'unknown'),
                    false
                );
            }
        }

        // Execute patches within a transaction
        $this->entityManager->beginTransaction();
        $results = [];

        try {
            foreach ($patches as $index => $patch) {
                $patchResult = $this->executePatch($patch, $index, $context);
                $results[] = $patchResult;
            }

            if ($dryRun) {
                // Rollback in dry-run mode - validation passed but don't persist
                $this->entityManager->rollback();
            } else {
                // Commit the transaction
                $this->entityManager->commit();
            }

            return PatchResult::success($dryRun, $results);
        } catch (PatchFailedException $e) {
            // Rollback on failure
            $this->entityManager->rollback();

            return PatchResult::failure(
                $dryRun,
                new PatchError($e->getMessage(), $e->getPatchIndex(), $e->getOperation(), $e->getNodeId()),
                true
            );
        } catch (\Exception $e) {
            // Rollback on any unexpected error
            $this->entityManager->rollback();

            return PatchResult::failure(
                $dryRun,
                new PatchError($e->getMessage(), 0, 'unknown'),
                true
            );
        }
    }

    /**
     * Execute a single patch operation.
     *
     * @param AbstractPatch $patch The patch to execute
     * @param int $index The index of the patch in the batch
     * @param Context $context The content context
     * @return array<string, mixed> The result of the patch operation
     * @throws PatchFailedException
     */
    private function executePatch(AbstractPatch $patch, int $index, Context $context): array
    {
        if ($patch instanceof CreateNodePatch) {
            return $this->executeCreateNode($patch, $index, $context);
        } elseif ($patch instanceof UpdateNodePatch) {
            $nodeId = $this->executeUpdateNode($patch, $index, $context);
            return [
                'index' => $index,
                'operation' => $patch->getOperation(),
                'nodeId' => $nodeId,
            ];
        } elseif ($patch instanceof MoveNodePatch) {
            $nodeId = $this->executeMoveNode($patch, $index, $context);
            return [
                'index' => $index,
                'operation' => $patch->getOperation(),
                'nodeId' => $nodeId,
            ];
        } elseif ($patch instanceof DeleteNodePatch) {
            $nodeId = $this->executeDeleteNode($patch, $index, $context);
            return [
                'index' => $index,
                'operation' => $patch->getOperation(),
                'nodeId' => $nodeId,
            ];
        }

        throw new PatchFailedException(
            sprintf('Unknown patch type: %s', get_class($patch)),
            $index,
            'unknown'
        );
    }

    /**
     * Execute a createNode patch.
     *
     * Returns extended information about all nodes that were created,
     * including auto-created child nodes and nodes from NodeTemplates.
     *
     * @param CreateNodePatch $patch
     * @param int $index
     * @param Context $context
     * @return array<string, mixed> The result with all created node details
     * @throws PatchFailedException
     */
    private function executeCreateNode(CreateNodePatch $patch, int $index, Context $context): array
    {
        try {
            $referenceNode = $context->getNodeByIdentifier($patch->getPositionRelativeToNodeId());
            if ($referenceNode === null) {
                throw new PatchFailedException(
                    sprintf('Reference node "%s" not found', $patch->getPositionRelativeToNodeId()),
                    $index,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }

            $nodeType = $this->nodeTypeManager->getNodeType($patch->getNodeType());

            // Create the node based on position
            if ($patch->getPosition() === 'into') {
                // For 'into', the reference node is the parent
                $nodeName = $this->generateUniqueNodeName($referenceNode, $nodeType);
                $newNode = $referenceNode->createNode($nodeName, $nodeType);
            } else {
                // For 'before' or 'after', the reference node is a sibling
                $actualParent = $referenceNode->getParent();
                if ($actualParent === null) {
                    throw new PatchFailedException(
                        sprintf('Reference node "%s" has no parent', $patch->getPositionRelativeToNodeId()),
                        $index,
                        'createNode',
                        $patch->getPositionRelativeToNodeId()
                    );
                }
                // Generate unique node name for the actual parent (not the reference sibling)
                $nodeName = $this->generateUniqueNodeName($actualParent, $nodeType);
                $newNode = $actualParent->createNode($nodeName, $nodeType);

                // Move to correct position
                if ($patch->getPosition() === 'before') {
                    $newNode->moveBefore($referenceNode);
                } else {
                    $newNode->moveAfter($referenceNode);
                }
            }

            // Normalize and set properties
            // This converts asset objects (with 'identifier' key) to plain identifier strings
            $normalizedProperties = $this->propertyNormalizer->normalizeProperties($patch->getProperties(), $nodeType);
            foreach ($normalizedProperties as $propertyName => $propertyValue) {
                $newNode->setProperty($propertyName, $propertyValue);
            }

            // Apply NodeTemplates if configured in the NodeType
            $this->templateNodeCreationHandler->handle($newNode, []);

            // Collect information about all created nodes (main node + auto-created children)
            $createdNodes = $this->collectCreatedNodes($newNode, 0);

            return [
                'index' => $index,
                'operation' => 'createNode',
                'nodeId' => $newNode->getIdentifier(),
                'createdNodes' => $createdNodes,
            ];
        } catch (PatchFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PatchFailedException(
                sprintf('Failed to create node: %s', $e->getMessage()),
                $index,
                'createNode',
                $patch->getPositionRelativeToNodeId(),
                $e
            );
        }
    }

    /**
     * Collect information about a node and all its descendants.
     *
     * This traverses the node tree to gather details about all nodes that
     * were created, including auto-created child nodes (fixed children)
     * and nodes created by NodeTemplates.
     *
     * @param NodeInterface $node The node to start collecting from
     * @param int $depth The depth relative to the main created node (0 = main node)
     * @return array<int, CreatedNodeInfo>
     */
    private function collectCreatedNodes(NodeInterface $node, int $depth): array
    {
        $createdNodes = [];

        // Add the current node
        $createdNodes[] = new CreatedNodeInfo(
            $node->getIdentifier(),
            $node->getNodeType()->getName(),
            $node->getName(),
            $this->extractNodeProperties($node),
            $depth
        );

        // Recursively collect all child nodes
        foreach ($node->getChildNodes() as $childNode) {
            $createdNodes = array_merge(
                $createdNodes,
                $this->collectCreatedNodes($childNode, $depth + 1)
            );
        }

        return $createdNodes;
    }

    /**
     * Extract properties from a node, filtering internal properties.
     *
     * Excludes properties starting with underscore (except _hidden),
     * and serializes assets appropriately.
     *
     * @param NodeInterface $node
     * @return array<string, mixed>
     */
    private function extractNodeProperties(NodeInterface $node): array
    {
        $properties = $node->getProperties();
        $result = [];

        foreach ($properties as $propertyName => $propertyValue) {
            // Filter internal properties (starting with underscore, except _hidden)
            if (str_starts_with($propertyName, '_') && $propertyName !== '_hidden') {
                continue;
            }

            // Serialize the property value
            $result[$propertyName] = $this->serializePropertyValue($propertyValue);
        }

        return $result;
    }

    /**
     * Serialize a property value to a JSON-compatible format.
     *
     * Handles special types like images and assets.
     *
     * @param mixed $value The property value
     * @return mixed The serialized value
     */
    private function serializePropertyValue(mixed $value): mixed
    {
        // Handle image assets (check Image first as it extends Asset)
        if ($value instanceof Image) {
            return [
                'identifier' => $value->getIdentifier(),
                'filename' => $value->getResource()?->getFilename() ?? '',
                'mediaType' => $value->getResource()?->getMediaType() ?? '',
            ];
        }

        // Handle general assets
        if ($value instanceof Asset) {
            return [
                'identifier' => $value->getIdentifier(),
                'filename' => $value->getResource()?->getFilename() ?? '',
                'mediaType' => $value->getResource()?->getMediaType() ?? '',
            ];
        }

        // Handle arrays (may contain nested assets)
        if (is_array($value)) {
            return array_map(fn($item) => $this->serializePropertyValue($item), $value);
        }

        // Handle DateTime objects
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        // Handle objects that cannot be serialized
        if (is_object($value)) {
            // Try to get a string representation
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            // Return null for objects that can't be serialized
            return null;
        }

        // Scalars and null pass through as-is
        return $value;
    }

    /**
     * Execute an updateNode patch.
     *
     * @param UpdateNodePatch $patch
     * @param int $index
     * @param Context $context
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeUpdateNode(UpdateNodePatch $patch, int $index, Context $context): string
    {
        try {
            $node = $context->getNodeByIdentifier($patch->getNodeId());
            if ($node === null) {
                throw new PatchFailedException(
                    sprintf('Node "%s" not found', $patch->getNodeId()),
                    $index,
                    'updateNode',
                    $patch->getNodeId()
                );
            }

            // Normalize and update properties
            // This converts asset objects (with 'identifier' key) to plain identifier strings
            $normalizedProperties = $this->propertyNormalizer->normalizeProperties($patch->getProperties(), $node->getNodeType());
            foreach ($normalizedProperties as $propertyName => $propertyValue) {
                $node->setProperty($propertyName, $propertyValue);
            }

            return $node->getIdentifier();
        } catch (PatchFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PatchFailedException(
                sprintf('Failed to update node: %s', $e->getMessage()),
                $index,
                'updateNode',
                $patch->getNodeId(),
                $e
            );
        }
    }

    /**
     * Execute a moveNode patch.
     *
     * @param MoveNodePatch $patch
     * @param int $index
     * @param Context $context
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeMoveNode(MoveNodePatch $patch, int $index, Context $context): string
    {
        try {
            $node = $context->getNodeByIdentifier($patch->getNodeId());
            if ($node === null) {
                throw new PatchFailedException(
                    sprintf('Node "%s" not found', $patch->getNodeId()),
                    $index,
                    'moveNode',
                    $patch->getNodeId()
                );
            }

            $targetNode = $context->getNodeByIdentifier($patch->getTargetNodeId());
            if ($targetNode === null) {
                throw new PatchFailedException(
                    sprintf('Target node "%s" not found', $patch->getTargetNodeId()),
                    $index,
                    'moveNode',
                    $patch->getNodeId()
                );
            }

            // Execute move based on position
            switch ($patch->getPosition()) {
                case 'into':
                    $node->moveInto($targetNode);
                    break;
                case 'before':
                    $node->moveBefore($targetNode);
                    break;
                case 'after':
                    $node->moveAfter($targetNode);
                    break;
            }

            return $node->getIdentifier();
        } catch (PatchFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PatchFailedException(
                sprintf('Failed to move node: %s', $e->getMessage()),
                $index,
                'moveNode',
                $patch->getNodeId(),
                $e
            );
        }
    }

    /**
     * Execute a deleteNode patch.
     *
     * @param DeleteNodePatch $patch
     * @param int $index
     * @param Context $context
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeDeleteNode(DeleteNodePatch $patch, int $index, Context $context): string
    {
        try {
            $node = $context->getNodeByIdentifier($patch->getNodeId());
            if ($node === null) {
                throw new PatchFailedException(
                    sprintf('Node "%s" not found', $patch->getNodeId()),
                    $index,
                    'deleteNode',
                    $patch->getNodeId()
                );
            }

            $nodeId = $node->getIdentifier();
            $node->remove();

            return $nodeId;
        } catch (PatchFailedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new PatchFailedException(
                sprintf('Failed to delete node: %s', $e->getMessage()),
                $index,
                'deleteNode',
                $patch->getNodeId(),
                $e
            );
        }
    }

    /**
     * Create a content context for the given workspace and dimensions.
     *
     * @param string $workspace
     * @param array<string, array<int, string>> $dimensions
     * @return Context
     */
    private function createContext(string $workspace, array $dimensions): Context
    {
        $contextProperties = [
            'workspaceName' => $workspace,
            'invisibleContentShown' => true,
            'removedContentShown' => false,
            'inaccessibleContentShown' => true,
        ];

        if (!empty($dimensions)) {
            $contextProperties['dimensions'] = $dimensions;
            // Use the first value of each dimension as target dimension
            $targetDimensions = [];
            foreach ($dimensions as $dimensionName => $dimensionValues) {
                $targetDimensions[$dimensionName] = reset($dimensionValues);
            }
            $contextProperties['targetDimensions'] = $targetDimensions;
        }

        return $this->contextFactory->create($contextProperties);
    }

    /**
     * Generate a unique node name based on the node type.
     *
     * @param NodeInterface $parentNode
     * @param NodeType $nodeType
     * @return string
     */
    private function generateUniqueNodeName(NodeInterface $parentNode, NodeType $nodeType): string
    {
        // Create a base name from the node type (e.g., "CodeQ.Site:Content.Text" -> "text")
        $nodeTypeParts = explode(':', $nodeType->getName());
        $shortName = end($nodeTypeParts);
        $shortNameParts = explode('.', $shortName);
        $baseName = strtolower(end($shortNameParts));

        // Append a unique suffix
        $uniqueName = $baseName . '-' . substr(uniqid(), -8);

        // Ensure uniqueness by checking if name already exists
        $counter = 0;
        $name = $uniqueName;
        while ($parentNode->getNode($name) !== null) {
            $counter++;
            $name = $uniqueName . '-' . $counter;
        }

        return $name;
    }
}

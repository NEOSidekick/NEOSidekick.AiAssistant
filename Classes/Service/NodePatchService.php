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
         * Apply a batch of content patches to the specified workspace and dimensions.
         *
         * Validates the provided patches, executes them inside a single database transaction,
         * and returns a PatchResult describing per-patch outcomes and overall success.
         * When $dryRun is true, patches are validated and executed but all changes are rolled back.
         *
         * @param array<int, array<string, mixed>> $patchesData Raw patch data from API request
         * @param string $workspace The workspace name to apply patches in
         * @param array<string, array<int, string>> $dimensions Content dimensions
         * @param bool $dryRun If true, validate and rollback without persisting
         * @return PatchResult PatchResult with per-patch results on success or a PatchError describing the failure (includes patch index, operation, and node identifier when available)
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
        // Track current patch index for error reporting in case of unexpected exceptions
        $currentIndex = 0;

        try {
            foreach ($patches as $index => $patch) {
                $currentIndex = $index;
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
                new PatchError($e->getMessage(), $currentIndex, 'unknown'),
                true
            );
        }
    }

    /**
         * Execute a single patch and return its result.
         *
         * Executes the provided patch and returns an associative array describing the outcome.
         *
         * @param AbstractPatch $patch The patch to execute.
         * @param int $index The index of the patch in the batch.
         * @param Context $context The content repository context to apply the patch in.
         * @return array<string,mixed> Associative array containing at minimum `index` and `operation`. For update/move/delete patches includes `nodeId`; for create patches includes `nodeId` and `createdNodes` with details of the created node tree.
         * @throws PatchFailedException If the patch cannot be applied or the patch type is unknown.
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
         * Create a new node according to the provided CreateNodePatch and return detailed information
         * about the created node(s).
         *
         * The result includes the patch index, the operation name, the identifier of the main created node,
         * and an array of created node details that covers the main node, any auto-created children, and
         * nodes added by NodeTemplates.
         *
         * @param CreateNodePatch $patch The create-node patch describing position, node type and properties.
         * @param int $index The zero-based index of the patch in the batch.
         * @param Context $context The content repository context to perform creation in.
         * @return array<string,mixed> Array with keys `index`, `operation`, `nodeId`, and `createdNodes`.
         * @throws PatchFailedException If the reference node or parent cannot be found or the creation fails.
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
         * Return the node's properties with internal fields removed and values serialized.
         *
         * Filters out properties whose names start with "_" except for "_hidden", then serializes each value
         * into a JSON-friendly representation (assets, dates, arrays, objects, scalars).
         *
         * @param NodeInterface $node The node to extract properties from.
         * @return array<string, mixed> An associative array mapping property names to their serialized values.
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
     * Serialize a content property value into a JSON-friendly representation.
     *
     * Converts known domain types and complex values into serializable forms so
     * they can be included in API responses or patch result payloads.
     *
     * @param mixed $value The property value to serialize.
     * @return mixed A JSON-serializable representation:
     *               - For Image and Asset objects: an associative array with keys
     *                 `identifier`, `filename`, and `mediaType`.
     *               - For arrays: an array with each item serialized recursively.
     *               - For DateTimeInterface: an ISO-8601 formatted string.
     *               - For objects implementing `__toString`: the string cast.
     *               - For other objects that cannot be stringified: `null`.
     *               - For scalars and `null`: the original value.
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
         * Update properties on the node targeted by the given patch.
         *
         * Normalizes the patch's properties according to the node type and applies them to the resolved node.
         *
         * @param UpdateNodePatch $patch Patch containing the target node identifier and properties to apply.
         * @param int $index Patch index used for error context.
         * @param Context $context Content context used to resolve the node by identifier.
         * @return string The identifier of the updated node.
         * @throws PatchFailedException If the target node cannot be found or the update fails.
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
     * Moves a node relative to a target node according to the provided MoveNodePatch.
     *
     * @param MoveNodePatch $patch Contains the source node identifier, target node identifier, and position ('into', 'before', 'after').
     * @param int $index Index of the patch in the batch, used for error reporting.
     * @param Context $context Content context used to resolve nodes.
     * @return string The moved node's identifier.
     * @throws PatchFailedException If the source or target node cannot be found or the move operation fails.
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
         * Delete the node referenced by the patch and return its identifier.
         *
         * @param DeleteNodePatch $patch The delete-node patch containing the target node identifier.
         * @param int $index The patch index within the batch (used for error context).
         * @param Context $context The content context used to resolve and modify the node.
         * @return string The identifier of the removed node.
         * @throws PatchFailedException If the target node is not found or deletion fails.
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
         * Builds a content Context for the specified workspace and dimension sets.
         *
         * If $dimensions is non-empty the context will include the full dimensions map and a
         * `targetDimensions` entry derived by taking the first value of each non-empty dimension.
         *
         * @param string $workspace The workspace name to use for the context.
         * @param array<string, array<int, string>> $dimensions Map of dimension names to ordered value lists; empty value arrays are ignored when deriving `targetDimensions`.
         * @return Context The created content Context configured with the provided workspace and dimensions.
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
            // Skip dimensions with empty value arrays to avoid reset() returning false
            $targetDimensions = [];
            foreach ($dimensions as $dimensionName => $dimensionValues) {
                if (!empty($dimensionValues)) {
                    $targetDimensions[$dimensionName] = reset($dimensionValues);
                }
            }
            $contextProperties['targetDimensions'] = $targetDimensions;
        }

        return $this->contextFactory->create($contextProperties);
    }

    /**
     * Create a unique, human-friendly node name derived from the given node type and guaranteed not to conflict with existing children of the parent node.
     *
     * @param NodeInterface $parentNode Parent node under which the name must be unique.
     * @param NodeType $nodeType Node type used to derive the base name.
     * @return string A unique node name suitable for creating a child node under $parentNode.
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
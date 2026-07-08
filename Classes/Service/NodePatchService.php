<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Flowpack\NodeTemplates\Domain\ErrorHandling\ProcessingErrors;
use Flowpack\NodeTemplates\Domain\NodeCreation\PropertiesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\ReferencesProcessor;
use Flowpack\NodeTemplates\Domain\NodeCreation\TransientNode;
use Flowpack\NodeTemplates\Domain\TemplateNodeCreationHandlerFactory;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeCreation\Dto\NodeAggregateIdsByNodePaths;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\DisableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Command\EnableNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeMove\Command\MoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeMove\Dto\RelationDistributionStrategy;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Command\SetNodeReferences;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesForName;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Dto\NodeReferencesToWrite;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSucceedingSiblingNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Node\ReferenceName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationCommands;
use Neos\Neos\Ui\Domain\NodeCreation\NodeCreationElements;
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
 * Patches are pre-validated (PatchValidator) and then applied as content
 * repository commands. Since the Neos 9 content repository is event-sourced,
 * there is no database-transaction based rollback anymore: patches that were
 * already applied when a later patch fails stay applied, and dry-run mode
 * performs validation only.
 */
class NodePatchService
{
    /**
     * @Flow\Inject
     * @var PatchValidator
     */
    protected $patchValidator;

    /**
     * @Flow\Inject
     * @var TemplateNodeCreationHandlerFactory
     */
    protected $templateNodeCreationHandlerFactory;

    /**
     * @Flow\Inject
     * @var PropertyNormalizer
     */
    protected $propertyNormalizer;

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
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * Apply a batch of patches to the content repository.
     *
     * All patches are pre-validated before any of them is executed. In dry-run
     * mode only the validation is performed and no changes are made.
     *
     * @param array<int, array<string, mixed>> $patchesData Raw patch data from API request
     * @param string $workspace The workspace name to apply patches in
     * @param array<string, array<int, string>> $dimensions Content dimensions
     * @param bool $dryRun If true, validate only without applying any changes
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

        // Resolve the content subgraph for the workspace and dimensions
        try {
            // TODO 9.0 migration: Make this code aware of multiple Content Repositories.
            $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
            // getContentSubgraph() applies no visibility restrictions, matching the
            // former context flags invisibleContentShown/inaccessibleContentShown = true
            $subgraph = $contentRepository->getContentSubgraph(
                WorkspaceName::fromString($workspace),
                $this->dimensionSpacePointFromLegacyDimensions($dimensions)
            );
        } catch (\Exception $e) {
            return PatchResult::failure(
                $dryRun,
                new PatchError($e->getMessage(), 0, 'unknown'),
                false
            );
        }

        // Pre-validate all patches before executing any of them
        foreach ($patches as $index => $patch) {
            try {
                $this->patchValidator->validatePatch($patch, $index, $subgraph);
            } catch (PatchFailedException $e) {
                return PatchResult::failure(
                    $dryRun,
                    new PatchError($e->getMessage(), $e->getPatchIndex(), $e->getOperation(), $e->getNodeId()),
                    false
                );
            }
        }

        if ($dryRun) {
            // TODO 9.0 migration (manual): the old implementation executed the patches in a
            // database transaction and rolled back afterwards, so dry-run results contained
            // e.g. the created node details. The event-sourced content repository has no
            // rollback, so dry-run now stops after validation and returns minimal results.
            $results = [];
            foreach ($patches as $index => $patch) {
                $results[] = [
                    'index' => $index,
                    'operation' => $patch->getOperation(),
                    'nodeId' => method_exists($patch, 'getNodeId') ? $patch->getNodeId() : null,
                ];
            }
            return PatchResult::success(true, $results);
        }

        // Execute patches sequentially
        $results = [];
        // Track current patch index for error reporting in case of unexpected exceptions
        $currentIndex = 0;

        try {
            foreach ($patches as $index => $patch) {
                $currentIndex = $index;
                $results[] = $this->executePatch($patch, $index, $contentRepository, $subgraph);
            }

            return PatchResult::success($dryRun, $results);
        } catch (PatchFailedException $e) {
            // TODO 9.0 migration (manual): patches applied before the failing one are NOT
            // rolled back anymore (event-sourced CR commands cannot be wrapped in a DB
            // transaction), hence rollbackPerformed=false in the result.
            return PatchResult::failure(
                $dryRun,
                new PatchError($e->getMessage(), $e->getPatchIndex(), $e->getOperation(), $e->getNodeId()),
                false
            );
        } catch (\Exception $e) {
            return PatchResult::failure(
                $dryRun,
                new PatchError($e->getMessage(), $currentIndex, 'unknown'),
                false
            );
        }
    }

    /**
     * Execute a single patch operation.
     *
     * @param AbstractPatch $patch The patch to execute
     * @param int $index The index of the patch in the batch
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph The content subgraph (workspace + dimensions)
     * @return array<string, mixed> The result of the patch operation
     * @throws PatchFailedException
     */
    private function executePatch(AbstractPatch $patch, int $index, ContentRepository $contentRepository, ContentSubgraphInterface $subgraph): array
    {
        if ($patch instanceof CreateNodePatch) {
            return $this->executeCreateNode($patch, $index, $contentRepository, $subgraph);
        } elseif ($patch instanceof UpdateNodePatch) {
            $nodeId = $this->executeUpdateNode($patch, $index, $contentRepository, $subgraph);
            return [
                'index' => $index,
                'operation' => $patch->getOperation(),
                'nodeId' => $nodeId,
            ];
        } elseif ($patch instanceof MoveNodePatch) {
            $nodeId = $this->executeMoveNode($patch, $index, $contentRepository, $subgraph);
            return [
                'index' => $index,
                'operation' => $patch->getOperation(),
                'nodeId' => $nodeId,
            ];
        } elseif ($patch instanceof DeleteNodePatch) {
            $nodeId = $this->executeDeleteNode($patch, $index, $contentRepository, $subgraph);
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
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph
     * @return array<string, mixed> The result with all created node details
     * @throws PatchFailedException
     */
    private function executeCreateNode(CreateNodePatch $patch, int $index, ContentRepository $contentRepository, ContentSubgraphInterface $subgraph): array
    {
        try {
            $referenceNode = $this->requireNode($patch->getPositionRelativeToNodeId(), $index, 'createNode', $subgraph);

            $nodeTypeManager = $contentRepository->getNodeTypeManager();
            $nodeType = $nodeTypeManager->getNodeType($patch->getNodeType());
            if ($nodeType === null) {
                throw new PatchFailedException(
                    sprintf('NodeType "%s" does not exist', $patch->getNodeType()),
                    $index,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }

            // Determine parent and succeeding sibling based on position.
            // A null succeeding sibling appends the new node as last child.
            if ($patch->getPosition() === 'into') {
                $parentNode = $referenceNode;
                $succeedingSibling = null;
            } else {
                $parentNode = $subgraph->findParentNode($referenceNode->aggregateId);
                if ($parentNode === null) {
                    throw new PatchFailedException(
                        sprintf('Reference node "%s" has no parent', $patch->getPositionRelativeToNodeId()),
                        $index,
                        'createNode',
                        $patch->getPositionRelativeToNodeId()
                    );
                }
                if ($patch->getPosition() === 'before') {
                    $succeedingSibling = $referenceNode;
                } else {
                    // 'after': insert before the reference node's next sibling (or as last child)
                    $succeedingSibling = $subgraph->findSucceedingSiblingNodes(
                        $referenceNode->aggregateId,
                        FindSucceedingSiblingNodesFilter::create()
                    )->first();
                }
            }

            $properties = $patch->getProperties();
            $hidden = $this->extractHiddenFlag($properties);

            // Normalize and convert properties; asset objects (with 'identifier' key) become
            // identifier strings, which the PropertiesProcessor property-maps to real objects
            $normalizedProperties = $this->propertyNormalizer->normalizeProperties($properties, $nodeType);

            $newNodeAggregateId = NodeAggregateId::create();
            $originDimensionSpacePoint = OriginDimensionSpacePoint::fromDimensionSpacePoint($subgraph->getDimensionSpacePoint());

            $transientNode = TransientNode::forRegular(
                $newNodeAggregateId,
                $subgraph->getWorkspaceName(),
                $originDimensionSpacePoint,
                $nodeType,
                NodeAggregateIdsByNodePaths::createForNodeType($nodeType->name, $nodeTypeManager),
                $nodeTypeManager,
                $subgraph,
                $normalizedProperties
            );
            $processingErrors = ProcessingErrors::create();
            $initialProperties = $this->propertiesProcessor->processAndValidateProperties($transientNode, $processingErrors);
            $references = $this->referencesProcessor->processAndValidateReferences($transientNode, $processingErrors);
            $this->throwOnProcessingErrors($processingErrors, $index, 'createNode', $patch->getPositionRelativeToNodeId());

            $createCommand = CreateNodeAggregateWithNode::create(
                $subgraph->getWorkspaceName(),
                $newNodeAggregateId,
                $nodeType->name,
                $originDimensionSpacePoint,
                $parentNode->aggregateId,
                $succeedingSibling?->aggregateId,
                empty($initialProperties) ? null : PropertyValuesToWrite::fromArray($initialProperties)
            );

            // Apply NodeTemplates if configured in the NodeType.
            // NodeCreationCommands/NodeCreationElements constructors are @internal, but this is
            // the same enrichment mechanism the Neos UI uses for node creation handlers.
            $commands = NodeCreationCommands::fromFirstCommand($createCommand, $nodeTypeManager);
            $commands = $this->templateNodeCreationHandlerFactory
                ->build($contentRepository)
                ->handle($commands, new NodeCreationElements([], []));

            foreach ($commands as $command) {
                $contentRepository->handle($command);
            }

            $referencesCommand = $this->createReferencesCommand($subgraph->getWorkspaceName(), $newNodeAggregateId, $originDimensionSpacePoint, $references);
            if ($referencesCommand !== null) {
                $contentRepository->handle($referencesCommand);
            }

            if ($hidden === true) {
                $contentRepository->handle(DisableNodeAggregate::create(
                    $subgraph->getWorkspaceName(),
                    $newNodeAggregateId,
                    $subgraph->getDimensionSpacePoint(),
                    NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS
                ));
            }

            // The projection is updated synchronously, so the new node is queryable right away
            $newNode = $subgraph->findNodeById($newNodeAggregateId);
            if ($newNode === null) {
                throw new PatchFailedException(
                    sprintf('Node "%s" was created but cannot be found in the subgraph', $newNodeAggregateId->value),
                    $index,
                    'createNode',
                    $patch->getPositionRelativeToNodeId()
                );
            }

            // Collect information about all created nodes (main node + auto-created children)
            $createdNodes = $this->collectCreatedNodes($newNode, 0);

            return [
                'index' => $index,
                'operation' => 'createNode',
                'nodeId' => $newNode->aggregateId->value,
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
     * were created, including auto-created child nodes (tethered children)
     * and nodes created by NodeTemplates.
     *
     * @param Node $node The node to start collecting from
     * @param int $depth The depth relative to the main created node (0 = main node)
     * @return array<int, CreatedNodeInfo>
     */
    private function collectCreatedNodes(Node $node, int $depth): array
    {
        $createdNodes = [];

        // Add the current node; non-tethered nodes have no node name in Neos 9
        $createdNodes[] = new CreatedNodeInfo(
            $node->aggregateId->value,
            $node->nodeTypeName->value,
            $node->name?->value ?? '',
            $this->extractNodeProperties($node),
            $depth
        );
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

        // Recursively collect all child nodes
        foreach ($subgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
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
     * @param Node $node
     * @return array<string, mixed>
     */
    private function extractNodeProperties(Node $node): array
    {
        $result = [];

        foreach ($node->properties as $propertyName => $propertyValue) {
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
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeUpdateNode(UpdateNodePatch $patch, int $index, ContentRepository $contentRepository, ContentSubgraphInterface $subgraph): string
    {
        try {
            $node = $this->requireNode($patch->getNodeId(), $index, 'updateNode', $subgraph);

            $nodeTypeManager = $contentRepository->getNodeTypeManager();
            $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
            if ($nodeType === null) {
                throw new PatchFailedException(
                    sprintf('NodeType "%s" of node "%s" is not known to the schema', $node->nodeTypeName->value, $patch->getNodeId()),
                    $index,
                    'updateNode',
                    $patch->getNodeId()
                );
            }

            $properties = $patch->getProperties();
            $hidden = $this->extractHiddenFlag($properties);

            // Normalize and convert properties; asset objects (with 'identifier' key) become
            // identifier strings, which the PropertiesProcessor property-maps to real objects
            $normalizedProperties = $this->propertyNormalizer->normalizeProperties($properties, $nodeType);

            $transientNode = TransientNode::forRegular(
                $node->aggregateId,
                $node->workspaceName,
                $node->originDimensionSpacePoint,
                $nodeType,
                NodeAggregateIdsByNodePaths::createEmpty(),
                $nodeTypeManager,
                $subgraph,
                $normalizedProperties
            );
            $processingErrors = ProcessingErrors::create();
            $propertyValues = $this->propertiesProcessor->processAndValidateProperties($transientNode, $processingErrors);
            $references = $this->referencesProcessor->processAndValidateReferences($transientNode, $processingErrors);
            $this->throwOnProcessingErrors($processingErrors, $index, 'updateNode', $patch->getNodeId());

            // TODO 9.0 migration (manual): properties are written to the node's origin dimension
            // space point; the old Context-based code would have materialized a new variant for
            // the requested dimensions if the node only existed as a fallback.
            if (!empty($propertyValues)) {
                $contentRepository->handle(SetNodeProperties::create(
                    $node->workspaceName,
                    $node->aggregateId,
                    $node->originDimensionSpacePoint,
                    PropertyValuesToWrite::fromArray($propertyValues)
                ));
            }

            $referencesCommand = $this->createReferencesCommand($node->workspaceName, $node->aggregateId, $node->originDimensionSpacePoint, $references);
            if ($referencesCommand !== null) {
                $contentRepository->handle($referencesCommand);
            }

            if ($hidden === true) {
                $contentRepository->handle(DisableNodeAggregate::create(
                    $node->workspaceName,
                    $node->aggregateId,
                    $subgraph->getDimensionSpacePoint(),
                    NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS
                ));
            } elseif ($hidden === false) {
                $contentRepository->handle(EnableNodeAggregate::create(
                    $node->workspaceName,
                    $node->aggregateId,
                    $subgraph->getDimensionSpacePoint(),
                    NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS
                ));
            }

            return $node->aggregateId->value;
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
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeMoveNode(MoveNodePatch $patch, int $index, ContentRepository $contentRepository, ContentSubgraphInterface $subgraph): string
    {
        try {
            $node = $this->requireNode($patch->getNodeId(), $index, 'moveNode', $subgraph);

            $targetNode = $this->requireNode($patch->getTargetNodeId(), $index, 'moveNode', $subgraph);

            // Determine new parent and siblings based on position
            $newPrecedingSibling = null;
            $newSucceedingSibling = null;
            if ($patch->getPosition() === 'into') {
                // No siblings given: the node is moved as last child of the target
                $newParentNode = $targetNode;
            } else {
                $newParentNode = $subgraph->findParentNode($targetNode->aggregateId);
                if ($newParentNode === null) {
                    throw new PatchFailedException(
                        sprintf('Target node "%s" has no parent', $patch->getTargetNodeId()),
                        $index,
                        'moveNode',
                        $patch->getNodeId()
                    );
                }
                if ($patch->getPosition() === 'before') {
                    $newSucceedingSibling = $targetNode;
                } else {
                    $newPrecedingSibling = $targetNode;
                }
            }

            $contentRepository->handle(MoveNodeAggregate::create(
                $subgraph->getWorkspaceName(),
                $subgraph->getDimensionSpacePoint(),
                $node->aggregateId,
                RelationDistributionStrategy::STRATEGY_GATHER_ALL,
                $newParentNode->aggregateId,
                $newPrecedingSibling?->aggregateId,
                $newSucceedingSibling?->aggregateId
            ));

            return $node->aggregateId->value;
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
     * @param ContentRepository $contentRepository
     * @param ContentSubgraphInterface $subgraph
     * @return string The node's identifier
     * @throws PatchFailedException
     */
    private function executeDeleteNode(DeleteNodePatch $patch, int $index, ContentRepository $contentRepository, ContentSubgraphInterface $subgraph): string
    {
        try {
            $node = $this->requireNode($patch->getNodeId(), $index, 'deleteNode', $subgraph);

            // allSpecializations is the closest equivalent to the old variant-scoped remove()
            $contentRepository->handle(RemoveNodeAggregate::create(
                $subgraph->getWorkspaceName(),
                $node->aggregateId,
                $subgraph->getDimensionSpacePoint(),
                NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS
            ));

            return $node->aggregateId->value;
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
     * Build the DimensionSpacePoint for the legacy dimensions array
     * (e.g. ['language' => ['de', 'en']]) by using the first value of each dimension,
     * mirroring the former targetDimensions logic.
     *
     * @param array<string, array<int, string>> $dimensions
     * @return DimensionSpacePoint
     */
    private function dimensionSpacePointFromLegacyDimensions(array $dimensions): DimensionSpacePoint
    {
        $coordinates = [];
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            if (!empty($dimensionValues)) {
                $coordinates[$dimensionName] = reset($dimensionValues);
            }
        }

        return DimensionSpacePoint::fromArray($coordinates);
    }

    /**
     * Resolve a node id string (plain NodeAggregateId or NodeAddress JSON) to a node
     * in the given subgraph, throwing PatchFailedException if it cannot be found.
     *
     * @param string $nodeId
     * @param int $index
     * @param string $operation
     * @param ContentSubgraphInterface $subgraph
     * @return Node
     * @throws PatchFailedException
     */
    private function requireNode(string $nodeId, int $index, string $operation, ContentSubgraphInterface $subgraph): Node
    {
        try {
            $nodeAggregateId = str_starts_with(ltrim($nodeId), '{')
                ? NodeAddress::fromJsonString($nodeId)->aggregateId
                : NodeAggregateId::fromString($nodeId);
        } catch (\Throwable $e) {
            throw new PatchFailedException(
                sprintf('Node identifier "%s" is not a valid node aggregate id or node address', $nodeId),
                $index,
                $operation,
                $nodeId,
                $e
            );
        }
        $node = $subgraph->findNodeById($nodeAggregateId);
        if ($node === null) {
            throw new PatchFailedException(
                sprintf('Node "%s" not found', $nodeId),
                $index,
                $operation,
                $nodeId
            );
        }

        return $node;
    }

    /**
     * Extract the legacy "_hidden" flag from the properties array and strip all other
     * internal (underscore-prefixed) properties, which cannot be written in Neos 9.
     *
     * @param array<string, mixed> $properties Passed by reference, internal properties are removed
     * @return bool|null The value of "_hidden" if it was set, null otherwise
     */
    private function extractHiddenFlag(array &$properties): ?bool
    {
        $hidden = null;
        if (array_key_exists('_hidden', $properties)) {
            $hidden = (bool)$properties['_hidden'];
        }
        foreach (array_keys($properties) as $propertyName) {
            if (str_starts_with((string)$propertyName, '_')) {
                // TODO 9.0 migration (manual): internal properties other than "_hidden"
                // (e.g. "_name", "_nodeType") are silently ignored now.
                unset($properties[$propertyName]);
            }
        }

        return $hidden;
    }

    /**
     * Build a SetNodeReferences command for the given processed references, if any.
     *
     * @param WorkspaceName $workspaceName
     * @param NodeAggregateId $nodeAggregateId
     * @param OriginDimensionSpacePoint $originDimensionSpacePoint
     * @param array<string, \Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds> $references
     * @return SetNodeReferences|null
     */
    private function createReferencesCommand(
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        OriginDimensionSpacePoint $originDimensionSpacePoint,
        array $references
    ): ?SetNodeReferences {
        $referencesForName = [];
        foreach ($references as $name => $nodeAggregateIds) {
            $referencesForName[] = NodeReferencesForName::fromTargets(
                ReferenceName::fromString($name),
                $nodeAggregateIds
            );
        }

        return empty($referencesForName)
            ? null
            : SetNodeReferences::create(
                $workspaceName,
                $nodeAggregateId,
                $originDimensionSpacePoint,
                NodeReferencesToWrite::create(...$referencesForName)
            );
    }

    /**
     * Throw a PatchFailedException for the first processing error, if any.
     *
     * @throws PatchFailedException
     */
    private function throwOnProcessingErrors(ProcessingErrors $processingErrors, int $index, string $operation, ?string $nodeId): void
    {
        if ($processingErrors->hasError()) {
            $firstError = $processingErrors->first();
            if ($firstError !== null) {
                throw new PatchFailedException(
                    $firstError->toMessage(),
                    $index,
                    $operation,
                    $nodeId
                );
            }
        }
    }
}

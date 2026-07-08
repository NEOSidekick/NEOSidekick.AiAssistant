<?php

namespace NEOSidekick\AiAssistant\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;
use NEOSidekick\AiAssistant\Factory\FindImageDataFactory;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeWithImageService extends AbstractNodeService
{
    /**
     * @Flow\Inject
     * @var FindImageDataFactory
     */
    protected $findImageDataFactory;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\Inject
     * @var NodeTypeService
     */
    protected $nodeTypeService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @param FindDocumentNodesFilter             $filter
     * @param array<string, FindDocumentNodeData> $findDocumentNodeDataDtos keyed by the document node's NodeAddress JSON string
     * @param ControllerContext                   $controllerContext
     *
     * @return array
     * @throws Exception
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws AssetServiceException
     * @throws ThumbnailServiceException
     */
    public function findDocumentNodesHavingChildNodesWithImages(FindDocumentNodesFilter $filter, array $findDocumentNodeDataDtos, ControllerContext $controllerContext): array
    {
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.
        $contentRepository = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString('default'));
        $workspace = $contentRepository->findWorkspaceByName(WorkspaceName::fromString($filter->getWorkspace()));

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $nodeTypeSchemaDtos = $this->nodeTypeService->getNodeTypesWithImageAlternativeTextOrTitleConfiguration();
        if ($nodeTypeSchemaDtos === []) {
            return [];
        }
        $contentNodeTypesFilter = implode(',', array_keys($nodeTypeSchemaDtos));
        $languageDimensionFilter = $filter->getLanguageDimensionFilter();

        $result = $findDocumentNodeDataDtos;
        $subgraphsByDimensionSpacePointHash = [];

        foreach ($findDocumentNodeDataDtos as $documentNodeAddressJson => $findDocumentNodeData) {
            try {
                $documentNodeAddress = NodeAddress::fromJsonString((string)$documentNodeAddressJson);
            } catch (InvalidArgumentException $e) {
                $this->systemLogger->warning(sprintf('Could not parse document node address "%s": %s', $documentNodeAddressJson, $e->getMessage()));
                continue;
            }

            // TODO 9.0 migration (manual): the old NodeData query also matched content variants without the language
            // dimension; we now filter by the document's dimension space point (a document address is dimension-specific).
            if (!empty($languageDimensionFilter)) {
                $languageCoordinate = $documentNodeAddress->dimensionSpacePoint->getCoordinate(new ContentDimensionId($this->languageDimensionName));
                if ($languageCoordinate !== null && !in_array($languageCoordinate, $languageDimensionFilter, true)) {
                    continue;
                }
            }

            // The workspace from the filter wins over the one encoded in the address, mirroring the old content context creation.
            // The content graph already resolves the workspace chain, replacing the old reduceNodeVariantsByWorkspaces().
            // NodeVisibility::excludeDisabledAndRemoved() excludes disabled nodes, replacing the old "n.hidden = false" constraint.
            $subgraph = $subgraphsByDimensionSpacePointHash[$documentNodeAddress->dimensionSpacePoint->hash]
                ??= $contentRepository->getContentGraph($workspace->workspaceName)
                    ->getSubgraph($documentNodeAddress->dimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved());

            $documentNode = $subgraph->findNodeById($documentNodeAddress->aggregateId);
            if ($documentNode === null) {
                continue;
            }

            // The document node itself can also carry image properties (the old query matched the exact path, too)
            $nodesToInspect = [$documentNode];
            // TODO 9.0 migration (manual): the old query ordered content nodes by path length and sorting index across
            // all documents; findDescendantNodes uses the graph's natural (tree) order, so image order can differ slightly.
            foreach ($subgraph->findDescendantNodes($documentNode->aggregateId, FindDescendantNodesFilter::create(nodeTypes: $contentNodeTypesFilter)) as $descendantNode) {
                $nodesToInspect[] = $descendantNode;
            }

            foreach ($nodesToInspect as $contentNode) {
                $imagePropertiesForNodeType = $nodeTypeSchemaDtos[$contentNode->nodeTypeName->value] ?? null;
                if (!$imagePropertiesForNodeType) {
                    continue;
                }

                // TODO 9.0 migration (manual): the old code attributed content to the closest "aggregate" ancestor;
                // we attribute to the closest document ancestor, which differs for content node types with aggregate=true.
                $closestDocumentNode = $subgraph->findClosestNode($contentNode->aggregateId, FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT));
                if ($closestDocumentNode === null) {
                    $this->systemLogger->warning(sprintf('Nodes must at least have one document ancestor, found node "%s" without.', NodeAddress::fromNode($contentNode)->toJson()));
                    continue;
                }
                // Content below a nested document is attributed to that document when it is iterated itself
                if (!$closestDocumentNode->aggregateId->equals($documentNode->aggregateId)) {
                    continue;
                }

                /** @var NodeTypeWithImageMetadataSchemaDto $schema */
                foreach ($imagePropertiesForNodeType as $schema) {
                    if (!self::nodeMatchesPropertyFilter($contentNode, $filter, $schema)) {
                        continue;
                    }

                    $findImageData = $this->findImageDataFactory->createFromNodeAndSchema($contentNode, $schema, $controllerContext);
                    if (!$findImageData) {
                        continue;
                    }
                    $result[$documentNodeAddressJson] = $result[$documentNodeAddressJson]->withAddedImage($findImageData);
                }
            }
        }

        return array_filter($result, static function (FindDocumentNodeData $findDocumentNodeData) {
            return count($findDocumentNodeData->getImages()) > 0;
        });
    }

    /**
     * @param Node                               $node
     * @param FindDocumentNodesFilter            $filter
     * @param NodeTypeWithImageMetadataSchemaDto $schema
     *
     * @return bool
     */
    private static function nodeMatchesPropertyFilter(Node $node, FindDocumentNodesFilter $filter, NodeTypeWithImageMetadataSchemaDto $schema): bool
    {
        $alternativeTextPropertyName = $schema->getAlternativeTextPropertyName();
        $alternativeTextPropertyValue = ($alternativeTextPropertyName && $node->hasProperty($alternativeTextPropertyName)) ? $node->getProperty($alternativeTextPropertyName) : null;
        $titleTextPropertyName = $schema->getTitleTextPropertyName();
        $titleTextPropertyValue = ($titleTextPropertyName && $node->hasProperty($titleTextPropertyName)) ? $node->getProperty($titleTextPropertyName) : null;
        $propertyValuesMatchFilter = match ($filter->getImagePropertiesFilter()) {
            'none' => true,
            'only-empty-alternative-text-or-title-text' => ($alternativeTextPropertyName && empty($alternativeTextPropertyValue)) || ($titleTextPropertyName && empty($titleTextPropertyValue)),
            'only-empty-alternative-text' => $alternativeTextPropertyName && empty($alternativeTextPropertyValue),
            'only-empty-title-text' => $titleTextPropertyName && empty($titleTextPropertyValue),
            'only-existing-alternative-text' => $alternativeTextPropertyName && !empty($alternativeTextPropertyValue),
            'only-existing-title-text' => $titleTextPropertyName && !empty($titleTextPropertyValue),
        };

        $imagePropertyIsNotEmpty = $node->hasProperty($schema->getImagePropertyName()) && $node->getProperty($schema->getImagePropertyName()) !== null;

        return $propertyValuesMatchFilter && $imagePropertyIsNotEmpty;
    }
}

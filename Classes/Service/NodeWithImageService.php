<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;
use NEOSidekick\AiAssistant\Factory\FindImageDataFactory;
use PDO;
use RuntimeException;

/**
 * @Flow\Scope("singleton")
 */
class NodeWithImageService extends AbstractNodeService
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var FindImageDataFactory
     */
    protected $findImageDataFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensions;

    /**
     * @Flow\Inject
     * @var NodeTypeService
     */
    protected $nodeTypeService;

    /**
     * @param FindDocumentNodesFilter     $filter
     * @param array<FindDocumentNodeData> $findDocumentNodeDataDtos
     * @param ControllerContext           $controllerContext
     *
     * @return array
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws Exception
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws AssetServiceException
     * @throws ThumbnailServiceException
     */
    public function findDocumentNodesHavingChildNodesWithImages(FindDocumentNodesFilter $filter, array $findDocumentNodeDataDtos, ControllerContext $controllerContext): array
    {
        $workspace = $this->workspaceRepository->findByIdentifier($filter->getWorkspace());
        $nodeTypeSchemaDtos = $this->nodeTypeService->getNodeTypesWithImageAlternativeTextOrTitleConfiguration();
        $documentNodeContextPaths = array_keys($findDocumentNodeDataDtos);

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $workspaceChain = array_merge([$workspace], array_values($workspace->getBaseWorkspaces()));
        $contentNodesQueryBuilder = $this->createQueryBuilder($workspaceChain);

        $contentNodesQueryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $contentNodesQueryBuilder->setParameter('includeNodeTypes', array_keys($nodeTypeSchemaDtos));

        $contentNodesQueryBuilder->andWhere('n.removed = :false');
        $contentNodesQueryBuilder->andWhere('n.hidden = :false');
        $contentNodesQueryBuilder->setParameter('false', false, PDO::PARAM_BOOL);

        // Only find nodes that are below or equal to the paths of the found document nodes
        $pathConstraints = $contentNodesQueryBuilder->expr()->orX();
        foreach ($documentNodeContextPaths as $contextPath) {
            $path = NodePaths::explodeContextPath($contextPath)['nodePath'];
            $pathConstraints->add($contentNodesQueryBuilder->expr()->eq('n.path', $contentNodesQueryBuilder->expr()->literal($path)));
            $pathConstraints->add($contentNodesQueryBuilder->expr()->like('n.path', $contentNodesQueryBuilder->expr()->literal($path . '%')));
        }
        $contentNodesQueryBuilder->andWhere($pathConstraints);

        if (!empty($filter->getLanguageDimensionFilter())) {
            $this->addDimensionJoinConstraintsToQueryBuilder($contentNodesQueryBuilder,
                [$this->languageDimensionName => $filter->getLanguageDimensionFilter()]);
        }

        $contentNodesQueryBuilder->addOrderBy('LENGTH(n.path)', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.index', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.dimensionsHash', 'DESC');

        $items = $contentNodesQueryBuilder->getQuery()->getResult();

        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);

        $result = $findDocumentNodeDataDtos;
        foreach ($itemsReducedByWorkspaceChain as $itemNodeData) {
            $closestAggregateNodeData = $this->findClosestAggregate($itemNodeData);

            if ($closestAggregateNodeData === null) {
                throw new RuntimeException('Nodes must at least have one aggregate ancestor', 1722372387256);
            }

            $context = $this->createContentContext($filter->getWorkspace(), $itemNodeData->getDimensionValues());
            $contentNode = new Node($itemNodeData, $context);
            $closestAggregateNode = $context->getNode($closestAggregateNodeData->getPath());

            $findDocumentNodeData = $result[$closestAggregateNode->getContextPath()] ?? null;
            // Skip if the document closest aggregate is not in the list of filtered document nodes
            if (!$findDocumentNodeData) {
                continue;
            }

            $imagePropertiesForNodeType = $nodeTypeSchemaDtos[$contentNode->getNodeType()->getName()];
            /** @var NodeTypeWithImageMetadataSchemaDto $schema */
            foreach ($imagePropertiesForNodeType as $schema) {
                if (!self::nodeMatchesPropertyFilter($itemNodeData, $filter, $schema)) {
                    continue;
                }

                $findImageData = $this->findImageDataFactory->createFromNodeAndSchema($contentNode, $schema, $controllerContext);
                if (!$findImageData) {
                    continue;
                }
                $result[$closestAggregateNodeData->getContextPath()] = $findDocumentNodeData->withAddedImage($findImageData);
            }
        }

        return array_filter($result, static function(FindDocumentNodeData $findDocumentNodeData) {
            return count($findDocumentNodeData->getImages()) > 0;
        });
    }

    /**
     * @param NodeData $nodeData
     *
     * @return NodeData|null
     * @throws NodeTypeNotFoundException
     */
    protected function findClosestAggregate(NodeData $nodeData): ?NodeData
    {
        $currentNode = $nodeData;
        while ($currentNode !== null) {
            if ($currentNode->getNodeType()->isAggregate()) {
                return $currentNode;
            }
            $currentNode = $currentNode->getParent();
        }
        return null;
    }

    /**
     * @param NodeData                           $nodeData
     * @param FindDocumentNodesFilter            $filter
     * @param NodeTypeWithImageMetadataSchemaDto $schema
     *
     * @return bool
     * @throws NodeException
     */
    private static function nodeMatchesPropertyFilter(NodeData $nodeData, FindDocumentNodesFilter $filter, NodeTypeWithImageMetadataSchemaDto $schema): bool
    {
        $alternativeTextPropertyName = $schema->getAlternativeTextPropertyName();
        $alternativeTextPropertyValue = ($alternativeTextPropertyName && $nodeData->hasProperty($alternativeTextPropertyName)) ? $nodeData->getProperty($alternativeTextPropertyName) : null;
        $titleTextPropertyName = $schema->getTitleTextPropertyName();
        $titleTextPropertyValue = ($titleTextPropertyName && $nodeData->hasProperty($titleTextPropertyName)) ? $nodeData->getProperty($titleTextPropertyName) : null;
        $propertyValuesMatchFilter = match($filter->getImagePropertiesFilter()) {
            'none' => true,
            'only-empty-alternative-text-or-title-text' => ($alternativeTextPropertyName && empty($alternativeTextPropertyValue)) || ($titleTextPropertyName && empty($titleTextPropertyValue)),
            'only-empty-alternative-text' => $alternativeTextPropertyName && empty($alternativeTextPropertyValue),
            'only-empty-title-text' => $titleTextPropertyName && empty($titleTextPropertyValue),
            'only-existing-alternative-text' => $alternativeTextPropertyName && !empty($alternativeTextPropertyValue),
            'only-existing-title-text' => $titleTextPropertyName && !empty($titleTextPropertyValue),
        };

        $imagePropertyIsNotEmpty = $nodeData->hasProperty($schema->getImagePropertyName()) && $nodeData->getProperty($schema->getImagePropertyName()) !== null;

        return $propertyValuesMatchFilter && $imagePropertyIsNotEmpty;
    }
}

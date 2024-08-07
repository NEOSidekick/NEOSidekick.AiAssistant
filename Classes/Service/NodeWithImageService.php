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
use NEOSidekick\AiAssistant\Dto\FindContentNodesFilter;
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
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

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

        $workspaceChain = [$workspace];
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

//        $itemsWithMatchingPropertyFilter = array_filter($itemsReducedByWorkspaceChain, static function(NodeData $nodeData) use ($filter) {
//            return self::nodeMatchesPropertyFilter($nodeData, $filter);
//        });

        $result = $findDocumentNodeDataDtos;
        foreach ($itemsReducedByWorkspaceChain as $item) {
            $closestAggregate = $this->findClosestAggregate($item);

            if ($closestAggregate === null) {
                throw new RuntimeException('Nodes must at least have one aggregate ancestor', 1722372387256);
            }

            $findDocumentNodeData = $result[$closestAggregate->getContextPath()] ?? null;
            // Skip if the document closest aggregate is not in the list of filtered document nodes
            if (!$findDocumentNodeData) {
                continue;
            }

            $context = $this->createContentContext($filter->getWorkspace(), $item->getDimensionValues());
            $contentNode = new Node($item, $context);
            $imagePropertiesForNodeType = $nodeTypeSchemaDtos[$contentNode->getNodeType()->getName()];
            /** @var NodeTypeWithImageMetadataSchemaDto $schema */
            foreach ($imagePropertiesForNodeType as $schema) {
                $findImageData = $this->findImageDataFactory->createFromNodeAndSchema($contentNode, $schema, $controllerContext);
                if (!$findImageData) {
                    continue;
                }
                $result[$closestAggregate->getContextPath()] = $findDocumentNodeData->withAddedImage($findImageData);
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
     * todo to be implemented
     *
     * @param NodeData               $nodeData
     * @param FindContentNodesFilter $filter
     *
     * @return bool
     * @throws NodeException
     */
    private static function nodeMatchesPropertyFilter(NodeData $nodeData, FindContentNodesFilter $filter)
    {
        // todo replace with extracted property name from configuration
        $alternativeTextPropertyMatchesFilter = match($filter->getAlternativeTextFilter()) {
            'none' => true,
            'only-empty' => !$nodeData->hasProperty('alternativeText') || $nodeData->getProperty('alternativeText') !== '',
        };

        // todo replace with extracted property name from configuration
        $imagePropertyIsNotEmpty = $nodeData->hasProperty('image') && $nodeData->getProperty('image') !== null;

        return $alternativeTextPropertyMatchesFilter && $imagePropertyIsNotEmpty;
    }
}

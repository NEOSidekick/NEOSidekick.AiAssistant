<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use NEOSidekick\AiAssistant\Dto\FindContentNodesFilter;
use NEOSidekick\AiAssistant\Factory\FindContentNodeDataFactory;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use PDO;
use RuntimeException;

/**
 * @Flow\Scope("singleton")
 */
class ContentNodeService extends AbstractNodeService
{
    const ASSET_URI_EXPRESSION = '/SidekickClientEval:\s*AssetUri\(node\.properties\.(\w+)\)/';
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
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

    /**
     * @Flow\Inject
     * @var FindContentNodeDataFactory
     */
    protected $findContentNodeDataFactory;

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

    public function findDocumentNodesHavingChildNodes(FindContentNodesFilter $filter, ControllerContext $controllerContext): array
    {
        // Fixture start
        $configuration = [
            'ui.inspector.editorOptions.module' => '/alt_tag_generator/',
            'ui.inspector.editorOptions.arguments.url' => self::ASSET_URI_EXPRESSION,
        ];
        $nodeTypes = $this->nodeTypeService->getNodeTypesMatchingConfiguration($configuration);
        // Fixture end

        $currentRequestHost = $controllerContext->getRequest()->getHttpRequest()->getUri()->getHost();
        $siteMatchingCurrentRequestHost = $this->siteService->getSiteByHostName($currentRequestHost);
        $workspace = $this->workspaceRepository->findByIdentifier($filter->getWorkspace());

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $workspaceChain = [$workspace];
        $contentNodesQueryBuilder = $this->createQueryBuilder($workspaceChain);
        $contentNodesQueryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $contentNodesQueryBuilder->andWhere('n.removed = :removed');
        $contentNodesQueryBuilder->andWhere('n.hidden = :hidden');
        $contentNodesQueryBuilder->andWhere($contentNodesQueryBuilder->expr()->orX(
            $contentNodesQueryBuilder->expr()->eq('n.path', ':currentSitePath'),
            $contentNodesQueryBuilder->expr()->like('n.path', ':currentSitePathWithWildcard')
        ));
        $contentNodesQueryBuilder->setParameter('currentSitePath', NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteMatchingCurrentRequestHost->getNodeName()));
        $contentNodesQueryBuilder->setParameter('currentSitePathWithWildcard', NodePaths::addNodePathSegment(SiteService::SITES_ROOT_PATH, $siteMatchingCurrentRequestHost->getNodeName()) . '%');
        $contentNodesQueryBuilder->setParameter('includeNodeTypes', array_keys($nodeTypes));
        $contentNodesQueryBuilder->setParameter('hidden', false, PDO::PARAM_BOOL);
        $contentNodesQueryBuilder->setParameter('removed', false, PDO::PARAM_BOOL);
        if (!empty($filter->getLanguageDimensionFilter())) {
            $this->addDimensionJoinConstraintsToQueryBuilder($contentNodesQueryBuilder,
                [$this->languageDimensionName => $filter->getLanguageDimensionFilter()]);
        }
        $contentNodesQueryBuilder->addOrderBy('LENGTH(n.path)', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.index', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.dimensionsHash', 'DESC');
        $items = $contentNodesQueryBuilder->getQuery()->getResult();

        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);

        $itemsWithMatchingPropertyFilter = array_filter($itemsReducedByWorkspaceChain, static function(NodeData $nodeData) use ($filter) {
            return self::nodeMatchesPropertyFilter($nodeData, $filter);
        });

        $result = [];
        foreach ($itemsWithMatchingPropertyFilter as $item) {
            $closestAggregate = $this->findClosestAggregate($item);

            if ($closestAggregate === null) {
                throw new RuntimeException('Nodes must at least have one aggregate ancestor', 1722372387256);
            }

            $context = $this->createContentContext('live', $closestAggregate->getDimensionValues());
            $findDocumentNodeData = $result[$closestAggregate->getContextPath()] ?? null;
            if (!isset($findDocumentNodeData)) {
                $closestAggregateNode = new Node($closestAggregate, $context);

                if ($this->isNodeHidden($closestAggregateNode)) {
                    continue;
                }

                $findDocumentNodeData = $this->findDocumentNodeDataFactory->createFromNode($closestAggregateNode, $controllerContext);
                $result[$closestAggregate->getContextPath()] = $findDocumentNodeData;
            }

            $contentNode = new Node($item, $context);
            $findContentNodeData = $this->findContentNodeDataFactory->createFromNode($contentNode);
            $result[$closestAggregate->getContextPath()] = $findDocumentNodeData->withAddedRelevantContentNode($findContentNodeData);
        }

        return $result;
    }

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
}

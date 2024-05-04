<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\QueryBuilder;
use Generator;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Neos\Controller\CreateContentContextTrait;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;

class NodeService
{
    use CreateContentContextTrait;

    private const BASE_NODE_TYPE = 'NEOSidekick.AiAssistant:Mixin.AiPageBriefing';

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
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     * @param ControllerContext   $controllerContext
     *
     * @return array
     */
    public function find(FindDocumentNodesFilter $findDocumentNodesFilter, ControllerContext $controllerContext): array
    {
        $workspace = $this->workspaceRepository->findByIdentifier($findDocumentNodesFilter->getWorkspace());

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $workspaceChain = array_merge([$workspace], array_values($workspace->getBaseWorkspaces()));
        $queryBuilder = $this->createQueryBuilder($workspaceChain);
        $queryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $queryBuilder->setParameter('includeNodeTypes', $this->getNodeTypeFilter($findDocumentNodesFilter));
        $items = $queryBuilder->getQuery()->getResult();
        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);
        $itemsWithMatchingPropertyFilter = array_filter($itemsReducedByWorkspaceChain, function(NodeData $nodeData) use ($findDocumentNodesFilter) {
            return self::nodeMatchesPropertyFilter($nodeData, $findDocumentNodesFilter);
        });

        $result = [];
        foreach ($itemsWithMatchingPropertyFilter as $nodeData) {
            $context = $this->createContentContext($findDocumentNodesFilter->getWorkspace(), $nodeData->getDimensionValues());
            $node = new Node($nodeData, $context);
            $result[] = $this->findDocumentNodeDataFactory->createFromNode($node, $controllerContext);
        }

        return $result;
    }

    /**
     * @param array<UpdateNodeProperties> $itemsToUpdate
     *
     * @return void
     */
    public function updatePropertiesOnNodes(array $itemsToUpdate): void
    {
        foreach($itemsToUpdate as $updateItem) {
            /** @var array{nodePath: string, workspaceName: string, dimensions: array} $contextPathSegments */
            $contextPathSegments = NodePaths::explodeContextPath($updateItem->getNodeContextPath());
            $context = $this->createContentContext($contextPathSegments['workspaceName'],
                $contextPathSegments['dimensions']);
            $node = $context->getNode($contextPathSegments['nodePath']);
            foreach ($updateItem->getProperties() as $propertyName => $propertyValue) {
                $node->setProperty($propertyName, $propertyValue);
            }
        }
    }

    /**
     * @param NodeData                $nodeData
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return bool
     */
    protected static function nodeMatchesPropertyFilter(NodeData $nodeData, FindDocumentNodesFilter $findDocumentNodesFilter): bool
    {
        $focusKeywordValue = $nodeData->hasProperty('focusKeyword') ? $nodeData->getProperty('focusKeyword') : null;
        $titleOverride = $nodeData->hasProperty('titleOverride') ? $nodeData->getProperty('titleOverride') : null;
        $metaDescription = $nodeData->hasProperty('metaDescription') ? $nodeData->getProperty('metaDescription') : null;
        return match ($findDocumentNodesFilter->getPropertyFilter()) {
            'none' => true,
            'only-empty-focus-keywords' => empty($focusKeywordValue),
            'only-existing-focus-keywords' => !empty($focusKeywordValue),
            'only-empty-seo-titles-or-meta-descriptions' => empty($titleOverride) || empty($metaDescription),
            'only-empty-seo-titles' => empty($titleOverride),
            'only-empty-meta-descriptions' => empty($metaDescription),
            'only-existing-seo-titles' => !empty($titleOverride),
            'only-existing-meta-descriptions' => !empty($metaDescription),
        };
    }

    /**
     * @copyright Taken from and adapted: \Neos\Flow\Persistence\Doctrine\Repository::iterate()
     *
     * @param IterableResult $iterator
     *
     * @return Generator
     */
    protected function iterate(IterableResult $iterator): Generator
    {
        foreach ($iterator as $object) {
            $object = current($object);
            yield $object;
        }
    }

    /**
     * This method returns an array of all possible node types
     * that are either a document node type OR match the filtered
     * document node type, but also have our mixin as a super-type.
     *
     * @param FindDocumentNodesFilter $findDocumentNodesFilter
     *
     * @return array<string>
     */
    protected function getNodeTypeFilter(FindDocumentNodesFilter $findDocumentNodesFilter): array
    {
        $documentNodeTypeFilter = $findDocumentNodesFilter->getNodeTypeFilter() ?? 'Neos.Neos:Document';
        $mixinSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes(self::BASE_NODE_TYPE, false);
        $mixinNameWithSubNodeTypes = [self::BASE_NODE_TYPE, ...array_keys($mixinSubNodeTypes)];
        $documentSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes($documentNodeTypeFilter, false);
        $documentNameWithSubNodeTypes = [$documentNodeTypeFilter, ...array_keys($documentSubNodeTypes)];
        $intersectNodeTypes = array_intersect(array_values($mixinNameWithSubNodeTypes), array_values($documentNameWithSubNodeTypes));
        return array_values($intersectNodeTypes);
    }

    /**
     * @copyright Taken from and adapted: \Neos\ContentRepository\Domain\Repository\NodeDataRepository::createQueryBuilder()
     *
     * @param array $workspaces
     * @return QueryBuilder
     */
    protected function createQueryBuilder(array $workspaces): QueryBuilder
    {
        $workspacesNames = array_map(static function (Workspace $workspace) {
            return $workspace->getName();
        }, $workspaces);

        $queryBuilder = $this->entityManager->createQueryBuilder();

        $queryBuilder->select('n')
            ->from(NodeData::class, 'n')
            ->where('n.workspace IN (:workspaces)')
            ->setParameter('workspaces', $workspacesNames);

        return $queryBuilder;
    }

    /**
     * @copyright Taken from and adapted: \Neos\ContentRepository\Domain\Repository\NodeDataRepository::reduceNodeVariantsByWorkspacesAndDimensions
     *
     * @param array<NodeData> $nodes
     * @param array<Workspace> $workspaces
     *
     * @return array<NodeData>
     */
    protected function reduceNodeVariantsByWorkspaces(array $nodes, array $workspaces): array
    {
        $reducedNodes = [];

        $minimalWorkspacePositionByIdentifier = [];

        $workspaceNames = array_map(
            function (Workspace $workspace) {
                return $workspace->getName();
            },
            $workspaces
        );
        foreach ($nodes as $node) {
            // Find the position of the workspace, a smaller value means more priority
            $workspacePosition = array_search($node->getWorkspace()->getName(), $workspaceNames);
            $identifier = $node->getIdentifier() . '-' . $node->getDimensionsHash();
            // Yes, it seems to work comparing arrays that way!
            if (!isset($minimalWorkspacePositionByIdentifier[$identifier]) || $workspacePosition < $minimalWorkspacePositionByIdentifier[$identifier]) {
                $reducedNodes[$identifier] = $node;
                $minimalWorkspacePositionByIdentifier[$identifier] = $workspacePosition;
            }
        }

        return $reducedNodes;
    }
}

<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\QueryBuilder;
use Generator;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;

abstract class AbstractNodeService
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @param IterableResult $iterator
     *
     * @return Generator
     * @copyright Taken from and adapted: \Neos\Flow\Persistence\Doctrine\Repository::iterate()
     *
     */
    protected static function iterate(IterableResult $iterator): Generator
    {
        foreach ($iterator as $object) {
            $object = current($object);
            yield $object;
        }
    }

    /**
     * @param array $workspaces
     *
     * @return QueryBuilder
     * @copyright Taken from and adapted: \Neos\ContentRepository\Domain\Repository\NodeDataRepository::createQueryBuilder()
     *
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
     * @copyright Taken from: Neos\ContentRepository\Domain\Repository\NodeDataRepository::addDimensionJoinConstraintsToQueryBuilder()
     *
     * If $dimensions is not empty, adds join constraints to the given $queryBuilder
     * limiting the query result to matching hits.
     *
     * @param QueryBuilder $queryBuilder
     * @param array $dimensions
     * @return void
     */
    protected function addDimensionJoinConstraintsToQueryBuilder(QueryBuilder $queryBuilder, array $dimensions): void
    {
        $count = 0;
        foreach ($dimensions as $dimensionName => $dimensionValues) {
            $dimensionAlias = 'd' . $count;
            $queryBuilder->andWhere(
                'EXISTS (SELECT ' . $dimensionAlias . ' FROM Neos\ContentRepository\Domain\Model\NodeDimension ' . $dimensionAlias . ' WHERE ' . $dimensionAlias . '.nodeData = n AND ' . $dimensionAlias . '.name = \'' . $dimensionName . '\' AND ' . $dimensionAlias . '.value IN (:' . $dimensionAlias . ')) ' .
                'OR NOT EXISTS (SELECT ' . $dimensionAlias . '_c FROM Neos\ContentRepository\Domain\Model\NodeDimension ' . $dimensionAlias . '_c WHERE ' . $dimensionAlias . '_c.nodeData = n AND ' . $dimensionAlias . '_c.name = \'' . $dimensionName . '\')'
            );
            $queryBuilder->setParameter($dimensionAlias, $dimensionValues);
            $count++;
        }
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
            static function (Workspace $workspace) {
                return $workspace->getName();
            },
            $workspaces
        );
        foreach ($nodes as $node) {
            // Find the position of the workspace, a smaller value means more priority
            $workspacePosition = array_search($node->getWorkspace()->getName(), $workspaceNames, true);
            $identifier = $node->getIdentifier() . '-' . $node->getDimensionsHash();
            // Yes, it seems to work comparing arrays that way!
            if (!isset($minimalWorkspacePositionByIdentifier[$identifier]) || $workspacePosition < $minimalWorkspacePositionByIdentifier[$identifier]) {
                $reducedNodes[$identifier] = $node;
                $minimalWorkspacePositionByIdentifier[$identifier] = $workspacePosition;
            }
        }

        return $reducedNodes;
    }

    protected function isNodeHidden(Node $node): bool
    {
        try {
            $parentNode = $node->findParentNode();
        } catch (NodeException $e) {
            // This is thrown if no more parent node is found and that means our Node is not hidden
            return false;
        }
        if ($parentNode->isHidden()) {
            return true;
        }

        return $this->isNodeHidden($parentNode);
    }
}

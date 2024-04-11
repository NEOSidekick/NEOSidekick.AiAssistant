<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Internal\Hydration\IterableResult;
use Doctrine\ORM\QueryBuilder;
use Generator;
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
use NEOSidekick\AiAssistant\Dto\FocusKeywordModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\FocusKeywordModuleResultDto;
use NEOSidekick\AiAssistant\Dto\ResultCollectionDto;
use NEOSidekick\AiAssistant\Factory\FocusKeywordModuleResultDtoFactory;

class NodeService
{
    use CreateContentContextTrait;

    private const MIXIN_NODE_TYPE = 'NEOSidekick.AiAssistant:Mixin.AiPageBriefing';

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
     * @var FocusKeywordModuleResultDtoFactory
     */
    protected $focusKeywordModuleResultDtoFactory;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    public function getNodesThatNeedProcessing(FocusKeywordModuleConfigurationDto $configurationDto, ControllerContext $controllerContext): ResultCollectionDto
    {
        $workspace = $this->workspaceRepository->findByIdentifier($configurationDto->getWorkspace());

        if (!$workspace) {
            // throw
        }

        $workspaceChain = array_merge([$workspace], array_values($workspace->getBaseWorkspaces()));
        $queryBuilder = $this->createQueryBuilder($workspaceChain);
        $queryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $queryBuilder->setParameter('includeNodeTypes', $this->getNodeTypeFilter($configurationDto));
        $iterator = $queryBuilder->getQuery()->iterate();

        $nodeDatasThatNeedProcessing = [];
        $nodeDatasThatNeedProcessingCount = 0;
        $iteratedItems = 0;

        /** @var NodeData $nodeData */
        foreach ($this->iterate($iterator) as $nodeData) {
            $iteratedItems++;
            if ($iteratedItems <= $configurationDto->getFirstResult()) {
                continue;
            }

            if ($nodeDatasThatNeedProcessingCount >= $configurationDto->getLimit()) {
                break;
            }

            $currentFocusKeywordPropertyValue = $nodeData->hasProperty('focusKeyword') ? $nodeData->getProperty('focusKeyword') : null;
            if (!self::matchFocusKeywordProperty($currentFocusKeywordPropertyValue, $configurationDto)) {
                continue;
            }

            $nodeDatasThatNeedProcessing[] = $nodeData;
            $nodeDatasThatNeedProcessingCount++;
        }

        $nodeDatasThatNeedProcessing = $this->reduceNodeVariantsByWorkspaces($nodeDatasThatNeedProcessing, $workspaceChain);

        $nodesThatNeedProcessing = [];
        foreach (array_slice($nodeDatasThatNeedProcessing, $configurationDto->getFirstResult(), $configurationDto->getLimit()) as $nodeData) {
            $context = $this->createContentContext($configurationDto->getWorkspace(), $nodeData->getDimensionValues());
            $node = new Node($nodeData, $context);
            $nodesThatNeedProcessing[] = $this->focusKeywordModuleResultDtoFactory->createFromNode($node, $controllerContext);
        }

        return new ResultCollectionDto(
            $nodesThatNeedProcessing,
            $configurationDto->getFirstResult() + sizeof($nodesThatNeedProcessing)
        );
    }

    /**
     * @param array<FocusKeywordModuleResultDto> $resultDtos
     *
     * @return void
     */
    public function updateFocusKeywordOnNodes(array $resultDtos): void
    {
        foreach($resultDtos as $resultDto) {
            /** @var array{nodePath: string, workspaceName: string, dimensions: array} $contextPathSegments */
            $contextPathSegments = NodePaths::explodeContextPath($resultDto->getNodeContextPath());
            $context = $this->createContentContext($contextPathSegments['workspaceName'],
                $contextPathSegments['dimensions']);
            $node = $context->getNode($contextPathSegments['nodePath']);
            $node->setProperty('focusKeyword', $resultDto->getFocusKeyword());
        }
    }

    protected static function matchFocusKeywordProperty(mixed $value, FocusKeywordModuleConfigurationDto $configurationDto): bool
    {
        if ($configurationDto->getMode() === 'both') {
            return true;
        }

        if ($configurationDto->getMode() === 'only-empty' && empty($value)) {
            return true;
        }

        if ($configurationDto->getMode() === 'only-existing' && !empty($value)) {
            return true;
        }

        return false;
    }

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
     * @param FocusKeywordModuleConfigurationDto $configurationDto
     *
     * @return array<string>
     */
    protected function getNodeTypeFilter(FocusKeywordModuleConfigurationDto $configurationDto): array
    {
        $documentNodeTypeFilter = $configurationDto->getNodeTypeFilter() ?? 'Neos.Neos:Document';
        $mixinSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes(self::MIXIN_NODE_TYPE, false);
        $documentSubNodeTypes = $this->nodeTypeManager->getSubNodeTypes($documentNodeTypeFilter, false);
        $intersectNodeTypes = array_intersect(array_values($mixinSubNodeTypes), array_intersect($documentSubNodeTypes));
        return array_values(['Neos.Neos:Document', ...array_map(fn (NodeType $nodeType) => $nodeType->getName(), $intersectNodeTypes)]);
    }

    /**
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

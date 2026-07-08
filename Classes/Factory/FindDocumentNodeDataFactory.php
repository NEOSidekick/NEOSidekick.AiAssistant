<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Service\LinkingService;
use Neos\Utility\Arrays;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;

/**
 * @Flow\Scope("singleton")
 */
class FindDocumentNodeDataFactory
{
    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $nodeLinkingService;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected string $languageDimensionName;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @throws NodeException
     * @throws \Neos\Flow\Security\Exception
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws Exception
     * @throws \Neos\Neos\Exception
     * @throws MissingActionNameException
     * @throws IllegalObjectTypeException
     */
    public function createFromNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, ControllerContext $controllerContext): FindDocumentNodeData
    {
        $publicUri = $previewUri = $this->nodeLinkingService->createNodeUri($controllerContext, $node, null, 'html', true);
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        if ($contentRepository->findWorkspaceByName($node->workspaceName)->getBaseWorkspace()) {
            // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
            $liveContext = $this->createContentContext('live', $node->getDimensions());
            $nodeInLiveContext = $liveContext->getNodeByIdentifier((string) $node->getNodeAggregateIdentifier());
            if ($nodeInLiveContext) {
                $publicUri = $this->nodeLinkingService->createNodeUri($controllerContext, $nodeInLiveContext, null, 'html', true);
            }
        }
        // TODO 9.0 migration: !! Node::getNodeData() - the new CR is not based around the concept of NodeData anymore. You need to rewrite your code here.
        return new FindDocumentNodeData(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            \Neos\ContentRepository\Core\SharedModel\Node\NodeAddress::fromNode($node)->toJson(),
            $node->nodeTypeName->value,
            $publicUri,
            $previewUri,
            (array)$node->properties,
            // todo inspect [0] syntax... maybe we also need a mapping? replace default value and/or discuss setup with and without language dimensions
            Arrays::getValueByPath($node->getNodeData()->getDimensionValues(), $this->languageDimensionName . '.0') ?: 'de'
        );
    }
}

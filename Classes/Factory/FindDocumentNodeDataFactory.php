<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
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

    public function createFromNode(Node $node, ControllerContext $controllerContext): FindDocumentNodeData
    {
        try {
            $publicUri = $this->nodeLinkingService->createNodeUri($controllerContext, $node, null, 'html', true);
        } catch (Exception|MissingActionNameException|IllegalObjectTypeException|\Neos\Flow\Property\Exception|\Neos\Flow\Security\Exception|\Neos\Neos\Exception|\Neos\Flow\Mvc\Exception\NoMatchingRouteException $e) {
            $publicUri = '';
        }
        return new FindDocumentNodeData(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $node->getNodeType()->getName(),
            $publicUri,
            (array)$node->getProperties(),
            // todo inspect [0] syntax... maybe we also need a mapping? replace default value and/or discuss setup with and without language dimensions
            Arrays::getValueByPath($node->getNodeData()->getDimensionValues(), $this->languageDimensionName . '.0') ?: 'de'
        );
    }
}

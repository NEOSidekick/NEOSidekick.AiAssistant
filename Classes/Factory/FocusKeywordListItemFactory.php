<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Service\LinkingService;
use NEOSidekick\AiAssistant\Dto\FocusKeywordListItem;

/**
 * @Flow\Scope("singleton")
 */
class FocusKeywordListItemFactory
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

    public function createFromNode(Node $node, ControllerContext $controllerContext): FocusKeywordListItem
    {
        try {
            $publicUri = $this->nodeLinkingService->createNodeUri($controllerContext, $node, null, 'html', true);
        } catch (Exception|MissingActionNameException|IllegalObjectTypeException|\Neos\Flow\Property\Exception|\Neos\Flow\Security\Exception|\Neos\Neos\Exception $e) {
            $publicUri = '';
        }
        return new FocusKeywordListItem(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $publicUri,
            $node->hasProperty('title') ? $node->getProperty('title') : '',
            ['focusKeyword' => $node->hasProperty('focusKeyword') ? $node->getProperty('focusKeyword') : ''],
            // todo inspect [0] syntax... maybe we also need a mapping?
            $node->getNodeData()->getDimensionValues()[$this->languageDimensionName][0]
        );
    }
}

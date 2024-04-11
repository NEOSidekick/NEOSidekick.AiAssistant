<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Service\LinkingService;
use NEOSidekick\AiAssistant\Dto\FocusKeywordModuleResultDto;

/**
 * @Flow\Scope("singleton")
 */
class FocusKeywordModuleResultDtoFactory
{
    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $nodeLinkingService;

    public function createFromNode(Node $node, ControllerContext $controllerContext): FocusKeywordModuleResultDto
    {
        try {
            $publicUri = $this->nodeLinkingService->createNodeUri($controllerContext, $node, null, 'html', true);
        } catch (Exception|MissingActionNameException|IllegalObjectTypeException|\Neos\Flow\Property\Exception|\Neos\Flow\Security\Exception|\Neos\Neos\Exception $e) {
            $publicUri = '';
        }
        return new FocusKeywordModuleResultDto(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $publicUri,
            $node->hasProperty('title') ? $node->getProperty('title') : '',
            $node->hasProperty('focusKeyword') ? $node->getProperty('focusKeyword') : '',
            // todo move "language" to the configuration later
            $node->getNodeData()->getDimensionValues()['language'][0]
        );
    }
}

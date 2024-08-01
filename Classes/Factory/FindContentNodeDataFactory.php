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
use Neos\Utility\Arrays;
use NEOSidekick\AiAssistant\Dto\FindContentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;

/**
 * @Flow\Scope("singleton")
 */
class FindContentNodeDataFactory
{
    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected string $languageDimensionName;

    /**
     * @throws NodeException
     * @throws \Neos\Flow\Security\Exception
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     */
    public function createFromNode(Node $node): FindContentNodeData
    {
        $relevantProperties = array_filter((array)$node->getProperties(), static function ($value, $key) {
            return in_array($key, ['image', 'alternativeText'], true);
        }, ARRAY_FILTER_USE_BOTH);
        return new FindContentNodeData(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $node->getNodeType()->getName(),
            $relevantProperties,
            // todo inspect [0] syntax... maybe we also need a mapping? replace default value and/or discuss setup with and without language dimensions
            Arrays::getValueByPath($node->getNodeData()->getDimensionValues(), $this->languageDimensionName . '.0') ?: 'de'
        );
    }
}

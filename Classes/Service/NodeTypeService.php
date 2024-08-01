<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeService
{
    /**
     * @Flow\Inject()
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @param array $configuration
     *
     * @return array<NodeType>
     */
    public function getNodeTypesMatchingConfiguration(array $configuration): array {
        $nodeTypes = $this->nodeTypeManager->getNodeTypes();
        $matchingNodeTypes = [];

        foreach ($nodeTypes as $nodeType) {
            $filteredProperties = $this->filterProperties($nodeType->getProperties(), $configuration);
            if (!empty($filteredProperties)) {
                $matchingNodeTypes[$nodeType->getName()] = $filteredProperties;
            }
        }

        return $matchingNodeTypes;
    }

    /**
     * @param array $properties
     * @param array $configuration
     *
     * @return array
     */
    private function filterProperties(array $properties, array $configuration): array {
        return array_filter($properties, static function($propertyConfiguration, $propertyName) use ($configuration) {
            if (str_starts_with($propertyName, '_') || str_starts_with($propertyName, 'neos_')) {
                return false;
            }

            foreach ($configuration as $configurationPath => $regularExpression) {
                $propertyConfigurationAtPath = Arrays::getValueByPath($propertyConfiguration, $configurationPath);
                if ($propertyConfigurationAtPath === null || !preg_match($regularExpression, $propertyConfigurationAtPath)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }
}

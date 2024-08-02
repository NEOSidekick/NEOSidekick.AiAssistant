<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeService
{
    public const ASSET_URI_EXPRESSION = '/SidekickClientEval:\s*AssetUri\(node\.properties\.(\w+)\)/';
    private const ALT_TAG_GENERATOR_MODULE = 'alt_tag_generator';

    /**
     * @Flow\Inject()
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    public function getNodeTypesWithImageAlternativeTextOrTitleConfiguration(): array
    {
        $nodeTypes = $this->nodeTypeManager->getNodeTypes();
        $matchingNodeTypes = $this->findMatchingNodeTypes($nodeTypes);

        return $this->createNodeTypeDtos($matchingNodeTypes);
    }

    private function findMatchingNodeTypes(array $nodeTypes): array
    {
        $matchingNodeTypes = [];
        foreach ($nodeTypes as $nodeType) {
            if ($this->isSkippableNodeType($nodeType)) {
                continue;
            }
            $this->processNodeTypeProperties($nodeType, $matchingNodeTypes);
        }
        return $matchingNodeTypes;
    }

    private function isSkippableNodeType($nodeType): bool
    {
        return $nodeType->isAbstract();
    }

    private function processNodeTypeProperties($nodeType, array &$matchingNodeTypes): void
    {
        foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
            if ($this->isSkippableProperty($propertyName)) {
                continue;
            }
            $this->processPropertyConfiguration($nodeType, $propertyName, $propertyConfiguration, $matchingNodeTypes);
        }
    }

    private function isSkippableProperty(string $propertyName): bool
    {
        return str_starts_with($propertyName, '_') || str_starts_with($propertyName, 'neos_');
    }

    private function processPropertyConfiguration($nodeType, string $propertyName, array $propertyConfiguration, array &$matchingNodeTypes): void
    {
        $module = Arrays::getValueByPath($propertyConfiguration, 'ui.inspector.editorOptions.module');
        if ($module === self::ALT_TAG_GENERATOR_MODULE) {
            $this->processAltTagGeneratorModule($nodeType, $propertyName, $propertyConfiguration, $matchingNodeTypes);
        }
    }

    private function processAltTagGeneratorModule($nodeType, string $propertyName, array $propertyConfiguration, array &$matchingNodeTypes): void
    {
        $matches = [];
        preg_match(self::ASSET_URI_EXPRESSION, Arrays::getValueByPath($propertyConfiguration, 'ui.inspector.editorOptions.arguments.url'), $matches);
        $imageProperty = $matches[1] ?? null;
        if ($imageProperty && array_key_exists($imageProperty, $nodeType->getProperties())) {
            $matchingNodeTypes[$nodeType->getName()][$imageProperty]['alternativeTextPropertyName'] = $propertyName;
        }
    }

    private function createNodeTypeDtos(array $matchingNodeTypes): array
    {
        $nodeTypeWithImageMetadataSchemaDtos = [];
        foreach ($matchingNodeTypes as $nodeTypeName => $imagePropertyNames) {
            foreach ($imagePropertyNames as $imagePropertyName => $propertyConfiguration) {
                if ($propertyConfiguration['alternativeTextPropertyName'] || $propertyConfiguration['titleTextPropertyName']) {
                    $nodeTypeWithImageMetadataSchemaDtos[] = new NodeTypeWithImageMetadataSchemaDto(
                        $nodeTypeName,
                        $imagePropertyName,
                        $propertyConfiguration['alternativeTextPropertyName'],
                        null
                    );
                }
            }
        }
        return $nodeTypeWithImageMetadataSchemaDtos;
    }
}

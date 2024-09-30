<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Cache\Exception;
use Neos\Cache\Frontend\VariableFrontend;
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
    private const IMAGE_ALTERNATIVE_TEXT_MODULE_NAME = 'image_alt_text';
    private const IMAGE_ALTERNATIVE_TEXT_MODULE_NAME_LEGACY = 'alt_tag_generator';
    private const IMAGE_TITLE_TEXT_MODULE_NAME = 'image_title';
    private const CACHE_ENTRY_IDENTIFIER = 'nodeTypesWithImageAlternativeTextOrTitleConfiguration';

    /**
     * @Flow\Inject()
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @var VariableFrontend
     */
    protected $cache;

    public function getNodeTypesWithImageAlternativeTextOrTitleConfiguration(): array
    {
        if ($this->cache->has(self::CACHE_ENTRY_IDENTIFIER)) {
            return $this->cache->get(self::CACHE_ENTRY_IDENTIFIER);
        }

        $nodeTypes = $this->nodeTypeManager->getNodeTypes();
        $matchingNodeTypes = $this->findMatchingNodeTypes($nodeTypes);

        $nodeTypeDtos = $this->createNodeTypeDtos($matchingNodeTypes);
        try {
            $this->cache->set(self::CACHE_ENTRY_IDENTIFIER, $nodeTypeDtos);
        } catch (Exception) {
            // A failure here should not break the application
        }

        return $nodeTypeDtos;
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
        if ($module === self::IMAGE_ALTERNATIVE_TEXT_MODULE_NAME || $module === self::IMAGE_ALTERNATIVE_TEXT_MODULE_NAME_LEGACY) {
            $this->processImageTextGeneratorModule($nodeType, 'alternativeTextPropertyName', $propertyName, $propertyConfiguration, $matchingNodeTypes);
        }
        if ($module === self::IMAGE_TITLE_TEXT_MODULE_NAME) {
            $this->processImageTextGeneratorModule($nodeType, 'titleTextPropertyName', $propertyName, $propertyConfiguration, $matchingNodeTypes);
        }
    }

    private function processImageTextGeneratorModule($nodeType, string $nodeTypeKey, string $propertyName, array $propertyConfiguration, array &$matchingNodeTypes): void
    {
        $matches = [];
        preg_match(self::ASSET_URI_EXPRESSION, Arrays::getValueByPath($propertyConfiguration, 'ui.inspector.editorOptions.arguments.url'), $matches);
        $imageProperty = $matches[1] ?? null;
        if ($imageProperty && array_key_exists($imageProperty, $nodeType->getProperties())) {
            $matchingNodeTypes[$nodeType->getName()][$imageProperty][$nodeTypeKey] = $propertyName;
        }
    }

    private function createNodeTypeDtos(array $matchingNodeTypes): array
    {
        $nodeTypeWithImageMetadataSchemaDtos = [];
        foreach ($matchingNodeTypes as $nodeTypeName => $imagePropertyNames) {
            foreach ($imagePropertyNames as $imagePropertyName => $propertyConfiguration) {
                if ($propertyConfiguration['alternativeTextPropertyName'] || $propertyConfiguration['titleTextPropertyName']) {
                    $nodeTypeWithImageMetadataSchemaDtos[$nodeTypeName][$imagePropertyName] = new NodeTypeWithImageMetadataSchemaDto(
                        $nodeTypeName,
                        $imagePropertyName,
                        $propertyConfiguration['alternativeTextPropertyName'] ?? null,
                        $propertyConfiguration['titleTextPropertyName'] ?? null
                    );
                }
            }
        }
        return $nodeTypeWithImageMetadataSchemaDtos;
    }
}

<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Cache\Exception;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\Arrays;
use NEOSidekick\AiAssistant\Dto\ImageTextPropertyConfigurationDto;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;
use NEOSidekick\AiAssistant\Exception\NodeTypeConfigurationException;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeService
{
    private const ASSET_URI_EXPRESSION = '/SidekickClientEval:\s*AssetUri\(node\.properties\.(\w+)\)/';
    private const IMAGE_ALTERNATIVE_TEXT_MODULE_NAMES = ['image_alt_text', 'alt_tag_generator'];
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

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

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
        } catch (Exception $e) {
            $this->logger->warning('Failed to set cache in NodeTypeService: ' . $e->getMessage());
        }

        return $nodeTypeDtos;
    }

    private function findMatchingNodeTypes(array $nodeTypes): array
    {
        $matchingNodeTypes = [];

        foreach ($nodeTypes as $nodeType) {
            if ($nodeType->isAbstract()) {
                continue;
            }

            $nodeTypeMatches = $this->processNodeTypeProperties($nodeType);
            if (!empty($nodeTypeMatches)) {
                $matchingNodeTypes[$nodeType->getName()] = $nodeTypeMatches;
            }
        }

        return $matchingNodeTypes;
    }

    /**
     * @throws NodeTypeConfigurationException
     */
    private function processNodeTypeProperties(NodeType $nodeType): array
    {
        $nodeTypeMatches = [];

        foreach ($nodeType->getProperties() as $propertyName => $propertyConfiguration) {
            if ($this->isSkippableProperty($propertyName)) {
                continue;
            }

            $propertyMatches = $this->processPropertyConfiguration($nodeType, $propertyName, $propertyConfiguration);
            if (!empty($propertyMatches)) {
                foreach ($propertyMatches as $imageTextGeneratorModuleDto) {
                    if (isset($nodeTypeMatches[$imageTextGeneratorModuleDto->getImagePropertyName()][$imageTextGeneratorModuleDto->getNodeTypeKey()])) {
                        throw new NodeTypeConfigurationException(sprintf('Error in node type "%s": there is already a "%s" property configured for image property "%s"', $nodeType->getName(), str_replace('PropertyName', '', $imageTextGeneratorModuleDto->getNodeTypeKey()), $imageTextGeneratorModuleDto->getImagePropertyName()), 1729598970759);
                    }

                    $nodeTypeMatches[$imageTextGeneratorModuleDto->getImagePropertyName()][$imageTextGeneratorModuleDto->getNodeTypeKey()] = $imageTextGeneratorModuleDto->getTextPropertyName();
                }
            }
        }

        return $nodeTypeMatches;
    }

    private function isSkippableProperty(string $propertyName): bool
    {
        return str_starts_with($propertyName, '_') || str_starts_with($propertyName, 'neos_');
    }

    /**
     * @param NodeType $nodeType
     * @param string   $propertyName
     * @param array    $propertyConfiguration
     *
     * @return ImageTextPropertyConfigurationDto[]
     */
    private function processPropertyConfiguration(NodeType $nodeType, string $propertyName, array $propertyConfiguration): array
    {
        $module = Arrays::getValueByPath($propertyConfiguration, 'ui.inspector.editorOptions.module');
        $propertyMatches = [];

        if (in_array($module, self::IMAGE_ALTERNATIVE_TEXT_MODULE_NAMES, true)) {
            $alternativeTextMatch = $this->processImageTextGeneratorModule($nodeType, 'alternativeTextPropertyName', $propertyName, $propertyConfiguration);
            if ($alternativeTextMatch) {
                $propertyMatches[] = $alternativeTextMatch;
            }
        }

        if ($module === self::IMAGE_TITLE_TEXT_MODULE_NAME) {
            $titleTextMatch = $this->processImageTextGeneratorModule($nodeType, 'titleTextPropertyName', $propertyName, $propertyConfiguration);
            if ($titleTextMatch) {
                $propertyMatches[] = $titleTextMatch;
            }
        }

        return $propertyMatches;
    }

    private function processImageTextGeneratorModule(NodeType $nodeType, string $nodeTypeKey, string $propertyName, array $propertyConfiguration): ?ImageTextPropertyConfigurationDto
    {
        $url = Arrays::getValueByPath($propertyConfiguration, 'ui.inspector.editorOptions.arguments.url', '');
        if (preg_match(self::ASSET_URI_EXPRESSION, $url, $matches)) {
            $imageProperty = $matches[1] ?? null;
            if ($imageProperty && array_key_exists($imageProperty, $nodeType->getProperties())) {
                return new ImageTextPropertyConfigurationDto(
                    $imageProperty,
                    $nodeTypeKey,
                    $propertyName
                );
            }
        }

        return null;
    }

    private function createNodeTypeDtos(array $matchingNodeTypes): array
    {
        $nodeTypeDtos = [];

        foreach ($matchingNodeTypes as $nodeTypeName => $imageProperties) {
            foreach ($imageProperties as $imagePropertyName => $propertyConfig) {
                $alternativeTextPropertyName = $propertyConfig['alternativeTextPropertyName'] ?? null;
                $titleTextPropertyName = $propertyConfig['titleTextPropertyName'] ?? null;

                if ($alternativeTextPropertyName || $titleTextPropertyName) {
                    $nodeTypeDtos[$nodeTypeName][$imagePropertyName] = new NodeTypeWithImageMetadataSchemaDto(
                        $nodeTypeName,
                        $imagePropertyName,
                        $alternativeTextPropertyName,
                        $titleTextPropertyName
                    );
                }
            }
        }

        return $nodeTypeDtos;
    }
}

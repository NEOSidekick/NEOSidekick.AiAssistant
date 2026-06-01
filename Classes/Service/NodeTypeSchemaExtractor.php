<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Locale;
use Neos\Flow\I18n\Translator;

/**
 * Service to extract raw NodeType schema data from Neos.
 *
 * This service provides the raw NodeType data that can be sent to the
 * NEOSidekick SaaS for TypeScript generation. It extracts all relevant
 * configuration and translates labels to the configured default language.
 *
 * @Flow\Scope("singleton")
 */
class NodeTypeSchemaExtractor
{
    /**
     * The NodeType name for ContentCollection which indicates the node itself is a collection.
     */
    private const CONTENT_COLLECTION_TYPE = 'Neos.Neos:ContentCollection';

    /**
     * NodeTypeManager provides FULLY RESOLVED NodeTypes.
     * All SuperType properties are already merged, all presets are already applied.
     *
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var Translator
     */
    protected $translator;

    /**
     * Default language for translations (from Neos.Neos.userInterface.defaultLanguage).
     *
     * @Flow\InjectConfiguration(path="userInterface.defaultLanguage", package="Neos.Neos")
     * @var string
     */
    protected string $defaultLanguage = 'en';

    /**
     * Extract raw NodeType schema for all NodeTypes.
     *
     * @param bool $includeAbstract Include abstract NodeTypes in output
     * @param string $filter Filter by NodeType prefix (e.g., "CodeQ.Site:")
     * @return array{generatedAt: string, nodeTypes: array<array>}
     */
    public function extract(bool $includeAbstract = false, string $filter = ''): array
    {
        $nodeTypes = $this->nodeTypeManager->getNodeTypes($includeAbstract);

        $schema = [];
        foreach ($nodeTypes as $nodeTypeName => $nodeType) {
            // Apply filter if specified
            if ($filter !== '' && strpos($nodeTypeName, $filter) !== 0) {
                continue;
            }

            $schema[] = $this->extractNodeType($nodeType);
        }

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'nodeTypes' => $schema,
        ];
    }

    /**
     * Extract schema data for a single NodeType.
     *
     * @return array{name: string, superTypes: array<string>, isContentCollection: bool, properties: array, childNodes: array, constraints: array}
     */
    private function extractNodeType(NodeType $nodeType): array
    {
        // Get declared superTypes (only the direct ones, not inherited)
        $superTypes = [];
        foreach ($nodeType->getDeclaredSuperTypes() as $superType) {
            $superTypes[] = $superType->getName();
        }

        return [
            'name' => $nodeType->getName(),
            'superTypes' => $superTypes,
            'isContentCollection' => $nodeType->isOfType(self::CONTENT_COLLECTION_TYPE),
            'properties' => $this->extractProperties($nodeType),
            'childNodes' => $nodeType->getConfiguration('childNodes') ?? [],
            'constraints' => $nodeType->getConfiguration('constraints') ?? [],
        ];
    }

    /**
     * Extract all properties with their full configuration.
     *
     * Returns the raw property configuration including type, ui, validation, and defaultValue.
     * All labels are translated to the configured default language.
     *
     * @return array<string, array>
     */
    private function extractProperties(NodeType $nodeType): array
    {
        $properties = $nodeType->getProperties();
        $result = [];

        foreach ($properties as $propertyName => $propertyConfig) {
            // Extract and translate UI configuration
            $ui = $propertyConfig['ui'] ?? [];
            $ui = $this->translateLabelsInConfig($ui);

            // Include the full property configuration
            $result[$propertyName] = [
                'type' => $propertyConfig['type'] ?? 'string',
                'defaultValue' => $propertyConfig['defaultValue'] ?? null,
                'ui' => $ui,
                'validation' => $propertyConfig['validation'] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Keys that should be translated in UI configuration.
     */
    private const TRANSLATABLE_KEYS = ['label', 'placeholder', 'helpMessage'];

    /**
     * Recursively translate translatable keys in a configuration array.
     *
     * Neos labels follow the format: PackageKey:SourceName:TranslationId
     * Example: Neos.Seo:NodeTypes.XmlSitemapMixin:properties.xmlSitemapChangeFrequency
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function translateLabelsInConfig(array $config): array
    {
        foreach ($config as $key => $value) {
            if (in_array($key, self::TRANSLATABLE_KEYS, true) && is_string($value)) {
                $config[$key] = $this->translateLabel($value);
            } elseif (is_array($value)) {
                $config[$key] = $this->translateLabelsInConfig($value);
            }
        }

        return $config;
    }

    /**
     * Translate a label string using the Neos I18n system.
     *
     * Labels follow the format: PackageKey:SourceName:TranslationId
     * Example: Neos.Seo:NodeTypes.XmlSitemapMixin:properties.xmlSitemapChangeFrequency
     *
     * The sourceName uses dots in the label but the translation files use slashes
     * (e.g., NodeTypes.XmlSitemapMixin -> NodeTypes/XmlSitemapMixin)
     *
     * @param string $label The label to translate
     * @return string The translated label or original if translation not found
     */
    private function translateLabel(string $label): string
    {
        // Labels without colons are not translation keys, return as-is
        if (strpos($label, ':') === false) {
            return $label;
        }

        $parts = explode(':', $label);
        if (count($parts) !== 3) {
            // Invalid format, return as-is
            return $label;
        }

        [$packageKey, $sourceName, $translationId] = $parts;

        // Convert dots to slashes in source name (NodeTypes.XmlSitemapMixin -> NodeTypes/XmlSitemapMixin)
        $sourceName = str_replace('.', '/', $sourceName);

        try {
            $locale = new Locale($this->defaultLanguage);
            $translated = $this->translator->translateById(
                $translationId,
                [],
                null,
                $locale,
                $sourceName,
                $packageKey
            );

            // translateById returns null if not found, fall back to original
            return $translated ?? $label;
        } catch (\Exception $e) {
            // On any error, return the original label
            return $label;
        }
    }
}

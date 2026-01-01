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
     * Build a raw, translatable schema representation for registered NodeTypes.
     *
     * @param bool $includeAbstract If true, include abstract NodeTypes in the result.
     * @param string $filter If non-empty, only include NodeTypes whose name starts with this prefix.
     * @return array{generatedAt: string, nodeTypes: array<array>} Array with `generatedAt` (ISO 8601 timestamp) and `nodeTypes` (list of per-NodeType schema entries).
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
     * Build a serializable schema entry for the given NodeType.
     *
     * The entry includes the NodeType name, its directly declared super types, whether it is a content collection,
     * the extracted properties (with translated UI labels), and the childNodes and constraints configurations.
     *
     * @param NodeType $nodeType The NodeType to extract (should be a fully resolved NodeType from NodeTypeManager).
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
     * Recursively translates translatable keys in a configuration array.
     *
     * Searches for keys listed in self::TRANSLATABLE_KEYS and, when their values are strings,
     * replaces them with the result of translateLabel(). Any nested arrays are processed recursively.
     *
     * @param array<string, mixed> $config Configuration data that may contain translatable entries.
     * @return array<string, mixed> The configuration with translated strings for translatable keys.
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
     * Translates a label using Neos I18n when it follows the PackageKey:SourceName:TranslationId format.
     *
     * If $label matches that format the method attempts a translation using the configured default language.
     * The SourceName portion may use dots in the key and is converted to a path (dots -> slashes) before lookup.
     * If the label is not a translation key, the translation is not found, or an error occurs, the original label is returned.
     *
     * @param string $label The label or translation key to translate (e.g. "Neos.Seo:NodeTypes.XmlSitemapMixin:properties.xmlSitemapChangeFrequency").
     * @return string The translated label if available, otherwise the original $label.
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
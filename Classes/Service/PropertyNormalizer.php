<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;

/**
 * Normalizes property values for Neos node operations.
 *
 * This service converts property values from LLM-friendly formats to the formats
 * expected by Neos. In particular, it handles asset properties where LLMs may
 * provide either:
 * - Just the identifier string: "27f03c9c-b09e-4ea4-b930-507904b7a0a0"
 * - Asset object: {"identifier": "27f03c9c-...", "filename": "image.jpg", "mediaType": "image/jpeg"}
 *
 * Since Neos expects just the identifier string for asset properties, this service
 * extracts the identifier from object format if provided.
 *
 * @Flow\Scope("singleton")
 */
class PropertyNormalizer
{
    /**
     * Property types that represent media assets.
     * When these types receive an object with 'identifier', we extract just the identifier.
     */
    private const ASSET_PROPERTY_TYPES = [
        'Neos\Media\Domain\Model\ImageInterface',
        'Neos\Media\Domain\Model\Asset',
        'Neos\Media\Domain\Model\AssetInterface',
        'Neos\Media\Domain\Model\Image',
    ];

    /**
     * Normalize properties for a given NodeType.
     *
     * Converts asset objects (with 'identifier' key) to plain identifier strings
     * for asset-type properties. This allows LLMs to use either format.
     *
     * @param array<string, mixed> $properties The properties to normalize
     * @param NodeType $nodeType The NodeType to check property types against
     * @return array<string, mixed> Normalized properties
     */
    public function normalizeProperties(array $properties, NodeType $nodeType): array
    {
        $normalized = [];
        foreach ($properties as $propertyName => $propertyValue) {
            $normalized[$propertyName] = $this->normalizePropertyValue($propertyValue, $propertyName, $nodeType);
        }
        return $normalized;
    }

    /**
     * Normalize a single property value.
     *
     * If the value is an asset object (array with 'identifier' key) and the
     * property type is an asset type, extract just the identifier string.
     *
     * @param mixed $value The property value to normalize
     * @param string $propertyName The property name
     * @param NodeType $nodeType The NodeType containing the property definition
     * @return mixed The normalized value
     */
    private function normalizePropertyValue(mixed $value, string $propertyName, NodeType $nodeType): mixed
    {
        // Handle null and non-array values directly
        if (!is_array($value)) {
            return $value;
        }

        // Get the property type from NodeType configuration using proper API
        $properties = $nodeType->getProperties();
        $propertyType = $properties[$propertyName]['type'] ?? null;

        // Check if this looks like an asset object (has 'identifier' key)
        if (isset($value['identifier']) && is_string($value['identifier'])) {
            // Check if this is an asset-type property
            if ($propertyType !== null && $this->isAssetPropertyType($propertyType)) {
                // Extract just the identifier for the property converter
                return $value['identifier'];
            }
        }

        // Handle arrays of assets (e.g., array<Neos\Media\Domain\Model\Asset>)
        if ($this->isIndexedArray($value)) {
            if ($propertyType !== null && $this->isAssetArrayPropertyType($propertyType)) {
                // Normalize each item in the array
                return array_map(function ($item) {
                    if (is_array($item) && isset($item['identifier']) && is_string($item['identifier'])) {
                        return $item['identifier'];
                    }
                    return $item;
                }, $value);
            }
        }

        // Return other arrays unchanged
        return $value;
    }

    /**
     * Check if a property type represents a media asset.
     *
     * @param string $propertyType The property type from NodeType configuration
     * @return bool
     */
    private function isAssetPropertyType(string $propertyType): bool
    {
        return in_array($propertyType, self::ASSET_PROPERTY_TYPES, true);
    }

    /**
     * Check if a property type represents an array of media assets.
     *
     * @param string $propertyType The property type from NodeType configuration
     * @return bool
     */
    private function isAssetArrayPropertyType(string $propertyType): bool
    {
        // Check for array notation like "array<Neos\Media\Domain\Model\Asset>"
        foreach (self::ASSET_PROPERTY_TYPES as $assetType) {
            if ($propertyType === 'array<' . $assetType . '>') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an array is an indexed (sequential) array.
     *
     * @param array<mixed> $array
     * @return bool
     */
    private function isIndexedArray(array $array): bool
    {
        if (empty($array)) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }
}


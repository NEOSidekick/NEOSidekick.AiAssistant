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
     * Normalize property values according to a NodeType's property types.
     *
     * Converts asset-shaped values (arrays containing an 'identifier' key) to identifier strings for properties declared as asset types.
     * For properties declared as arrays of assets, each item is normalized similarly. Non-asset values are returned unchanged.
     *
     * @param array<string, mixed> $properties Map of property names to values to normalize.
     * @param NodeType $nodeType NodeType metadata used to determine each property's configured type.
     * @return array<string, mixed> Normalized properties with asset values converted to identifier strings where applicable.
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
     * Normalize a single node property value to a Neos-compatible format.
     *
     * When the value represents an asset (an array containing an `identifier`) and the
     * NodeType declares the property as an asset type, returns the identifier string.
     * When the value is an indexed array and the NodeType declares an array of assets,
     * returns a new array where each asset-like item is replaced by its `identifier`
     * when present; other values are left unchanged. Non-asset shapes are returned as-is.
     *
     * @param mixed $value The property value to normalize.
     * @param string $propertyName The name of the property on the node type.
     * @param NodeType $nodeType The NodeType defining the property's type information.
     * @return mixed The normalized property value (possibly an identifier string, an array of identifiers, or the original value).
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
     * Determines whether a NodeType property type represents a media asset.
     *
     * @param string $propertyType Property type string from NodeType configuration.
     * @return bool `true` if the property type matches one of the asset-related types, `false` otherwise.
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

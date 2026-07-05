<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Maps node variants to language dimension presets.
 *
 * Depending on how (and with which Neos version) a variant was created, its NodeData
 * stores either only the primary dimension value (modern adoptNode / createVariantForContext
 * persist the target dimension values, e.g. ["sl"]) or the full fallback chain of the
 * preset it was created in (legacy content and contexts with multi-value target
 * dimensions, e.g. ["sl", "de"] for a preset "sl" falling back to "de").
 *
 * Because stored values can contain the whole fallback chain, checking whether a selected
 * dimension value is merely *contained* in the variant's values over-matches: filtering
 * for "de" would also match every variant of a language that only falls back to German.
 *
 * A variant belongs to a preset if its stored values equal a leading part of the preset's
 * configured fallback chain (compared order-insensitively, as NodeData persists dimension
 * values in sorted order). This covers both storage shapes without matching variants of
 * other languages that merely share a fallback value.
 *
 * @Flow\Proxy(false)
 */
final class LanguageDimensionPresetMatcher
{
    /**
     * @param array<string> $nodeDimensionValues values of the language dimension stored on the node variant
     * @param array<string> $selectedPresetIdentifiers preset identifiers selected in the filter
     * @param array<string, array{values?: array<string>}> $presetsConfiguration "presets" part of the dimension configuration
     */
    public static function matchesAnyPreset(
        array $nodeDimensionValues,
        array $selectedPresetIdentifiers,
        array $presetsConfiguration
    ): bool {
        $nodeDimensionValues = array_values($nodeDimensionValues);
        sort($nodeDimensionValues);
        if ($nodeDimensionValues === []) {
            return false;
        }

        foreach ($selectedPresetIdentifiers as $presetIdentifier) {
            foreach (self::chainPrefixesOfPreset($presetIdentifier, $presetsConfiguration) as $chainPrefix) {
                if ($chainPrefix === $nodeDimensionValues) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * All configured dimension values of the selected presets (for use as a database
     * pre-filter — preset identifiers are not necessarily dimension values).
     *
     * @param array<string> $selectedPresetIdentifiers
     * @param array<string, array{values?: array<string>}> $presetsConfiguration
     * @return array<string>
     */
    public static function collectDimensionValuesOfPresets(
        array $selectedPresetIdentifiers,
        array $presetsConfiguration
    ): array {
        $values = [];
        foreach ($selectedPresetIdentifiers as $presetIdentifier) {
            $presetValues = $presetsConfiguration[$presetIdentifier]['values'] ?? null;
            if (!is_array($presetValues) || $presetValues === []) {
                $presetValues = [$presetIdentifier];
            }
            foreach ($presetValues as $value) {
                $values[$value] = true;
            }
        }

        return array_keys($values);
    }

    /**
     * All leading parts of the preset's fallback chain, each sorted for comparison
     * with the (sorted) dimension values stored on NodeData.
     *
     * @param array<string, array{values?: array<string>}> $presetsConfiguration
     * @return array<array<string>>
     */
    private static function chainPrefixesOfPreset(string $presetIdentifier, array $presetsConfiguration): array
    {
        $presetValues = $presetsConfiguration[$presetIdentifier]['values'] ?? null;
        if (!is_array($presetValues) || $presetValues === []) {
            // No configured values: fall back to treating the identifier itself as the value
            $presetValues = [$presetIdentifier];
        }
        $presetValues = array_values($presetValues);

        $prefixes = [];
        for ($length = 1, $count = count($presetValues); $length <= $count; $length++) {
            $chainPrefix = array_slice($presetValues, 0, $length);
            sort($chainPrefix);
            $prefixes[] = $chainPrefix;
        }

        return $prefixes;
    }
}

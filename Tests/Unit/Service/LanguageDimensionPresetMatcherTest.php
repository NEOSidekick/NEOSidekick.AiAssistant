<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use NEOSidekick\AiAssistant\Service\LanguageDimensionPresetMatcher;
use PHPUnit\Framework\TestCase;

class LanguageDimensionPresetMatcherTest extends TestCase
{
    /**
     * Presets as configured on a typical multi-language site where secondary
     * languages fall back to German. Depending on how a variant was created, its
     * NodeData stores either just the primary value (e.g. ["sl"]) or the full
     * fallback chain (e.g. ["sl", "de"]) — both shapes must be classified correctly.
     *
     * @var array<string, array{values: array<string>}>
     */
    private const PRESETS = [
        'de' => ['values' => ['de']],
        'en' => ['values' => ['en', 'de']],
        'sl' => ['values' => ['sl', 'de']],
    ];

    /**
     * @test
     */
    public function variantOfFallbackLanguageDoesNotMatchTheBaseLanguageFilter(): void
    {
        // A Slovenian variant (fallback chain contains "de") must NOT match a "de"-only filter.
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['sl', 'de'], ['de'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function baseLanguageVariantMatchesTheBaseLanguageFilter(): void
    {
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['de'], ['de'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function variantMatchesItsOwnPresetByFullFallbackChain(): void
    {
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['sl', 'de'], ['sl'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function baseLanguageVariantDoesNotMatchAFallbackLanguageFilter(): void
    {
        // A German page must not appear when only Slovenian is selected, even though
        // Slovenian falls back to German.
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['de'], ['sl'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function comparisonIsOrderInsensitive(): void
    {
        // NodeData persists dimension values sorted; preset configuration lists them
        // in fallback priority order. Both notations must be treated as equal.
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['de', 'sl'], ['sl'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function variantsWithDistinctChainsAreDistinguished(): void
    {
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['en', 'de'], ['en', 'sl'], self::PRESETS)
        );
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['sl', 'de'], ['en', 'de'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function singleValueVariantMatchesItsPresetByPrimaryValue(): void
    {
        // Modern variant creation (adoptNode / createVariantForContext) stores only
        // the primary value, so ["sl"] belongs to the "sl" preset...
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['sl'], ['sl'], self::PRESETS)
        );
        // ...and must not appear when filtering for German.
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['sl'], ['de'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function variantWithoutDimensionValuesMatchesNothing(): void
    {
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset([], ['de', 'en', 'sl'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function presetWithoutConfiguredValuesFallsBackToItsIdentifier(): void
    {
        self::assertTrue(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['fr'], ['fr'], self::PRESETS)
        );
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['de'], ['fr'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function collectsDimensionValuesOfSelectedPresets(): void
    {
        self::assertSame(
            ['de'],
            LanguageDimensionPresetMatcher::collectDimensionValuesOfPresets(['de'], self::PRESETS)
        );
        self::assertSame(
            ['sl', 'de'],
            LanguageDimensionPresetMatcher::collectDimensionValuesOfPresets(['sl'], self::PRESETS)
        );
        self::assertSame(
            ['en', 'de', 'sl'],
            LanguageDimensionPresetMatcher::collectDimensionValuesOfPresets(['en', 'sl'], self::PRESETS)
        );
        // Unknown presets fall back to their identifier as value
        self::assertSame(
            ['fr'],
            LanguageDimensionPresetMatcher::collectDimensionValuesOfPresets(['fr'], self::PRESETS)
        );
    }

    /**
     * @test
     */
    public function noSelectedPresetMatchesNothing(): void
    {
        // The empty-filter short-circuit ("no restriction") lives in the calling code;
        // the matcher itself finds no preset to compare against.
        self::assertFalse(
            LanguageDimensionPresetMatcher::matchesAnyPreset(['de'], [], self::PRESETS)
        );
    }
}

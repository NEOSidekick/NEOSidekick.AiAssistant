<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use Neos\ContentRepository\Domain\Service\ConfigurationContentDimensionPresetSource;
use NEOSidekick\AiAssistant\Exception\InvalidContentDimensionsException;
use NEOSidekick\AiAssistant\Service\ContentDimensionResolver;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The dimensions an API caller names are checked against the site's configuration and completed.
 *
 * The site has two dimensions. `language`: German (default), Swiss German falling back to German,
 * French kept in sync and not allowed in Austria, English translated once. `country`: Austria
 * (default) and Switzerland.
 */
class ContentDimensionResolverTest extends TestCase
{
    private const CONFIGURATION = [
        'language' => [
            'label' => 'Language',
            'default' => 'de',
            'defaultPreset' => 'de',
            'presets' => [
                'de' => ['label' => 'Deutsch', 'values' => ['de'], 'options' => ['translationStrategy' => 'sync']],
                'swiss' => ['label' => 'Schweizerdeutsch', 'values' => ['de_CH', 'de']],
                'fr' => [
                    'label' => 'Français',
                    'values' => ['fr'],
                    'constraints' => ['country' => ['at' => false]],
                    'options' => ['translationStrategy' => 'sync'],
                ],
                'en' => ['label' => 'English', 'values' => ['en'], 'options' => ['translationStrategy' => 'once']],
                // a preset the site unset with `~`
                'nl' => null,
            ],
        ],
        'country' => [
            'label' => 'Country',
            'default' => 'at',
            'defaultPreset' => 'at',
            'presets' => [
                'at' => ['label' => 'Österreich', 'values' => ['at']],
                'ch' => ['label' => 'Schweiz', 'values' => ['ch']],
            ],
        ],
    ];

    /**
     * @test
     */
    public function namingNoDimensionGivesTheDefaultPresetOfEveryDimension(): void
    {
        $resolver = $this->createResolver();

        self::assertSame(['language' => ['de'], 'country' => ['at']], $resolver->resolve([]));
        self::assertSame(['language' => ['de'], 'country' => ['at']], $resolver->resolve(null));
        self::assertSame(['language' => ['de'], 'country' => ['at']], $resolver->resolve(['language' => []]));
    }

    /**
     * @return array<string, array{array<int, string>}>
     */
    public static function waysToNameSwissGerman(): array
    {
        return [
            'its values' => [['de_CH', 'de']],
            'its first value' => [['de_CH']],
            'its preset key' => [['swiss']],
            'its first value with another tail' => [['de_CH', 'fr']],
        ];
    }

    /**
     * @test
     * @dataProvider waysToNameSwissGerman
     * @param array<int, string> $values
     */
    public function aNamedPresetIsReplacedByItsFallbackChain(array $values): void
    {
        self::assertSame(
            ['language' => ['de_CH', 'de'], 'country' => ['ch']],
            $this->createResolver()->resolve(['country' => ['ch'], 'language' => $values])
        );
    }

    /**
     * @test
     */
    public function aValueIsTriedBeforeAPresetKey(): void
    {
        $resolver = $this->createResolver([
            'language' => [
                'label' => 'Language',
                'default' => 'en',
                'defaultPreset' => 'english',
                'presets' => [
                    'english' => ['label' => 'English', 'values' => ['en']],
                    'en' => ['label' => 'American English', 'values' => ['en_US', 'en']],
                ],
            ],
        ]);

        self::assertSame(['language' => ['en']], $resolver->resolve(['language' => ['en']]));
        self::assertSame(['language' => ['en_US', 'en']], $resolver->resolve(['language' => ['en_US']]));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function refusedDimensions(): array
    {
        return [
            'an unknown dimension' => [
                ['region' => ['eu']],
                '"region" is not a content dimension of this site. Configured dimensions: language, country.',
            ],
            'an unknown value' => [
                ['language' => ['es']],
                '["es"] is not a configured preset of the "language" dimension. Use the values of one of: '
                . '["de"] ("Deutsch"), ["de_CH","de"] ("Schweizerdeutsch"), ["fr"] ("Français"), ["en"] ("English").',
            ],
            'a preset the site unset' => [
                ['language' => ['nl']],
                '["nl"] is not a configured preset of the "language" dimension.',
            ],
            'a preset key followed by other values' => [
                ['language' => ['swiss', 'de']],
                '["swiss","de"] is not a configured preset of the "language" dimension.',
            ],
            'a list instead of an object' => [
                ['de'],
                '"dimensions" must be an object such as {"language": ["en"]}.',
            ],
            'a string instead of an object' => [
                'de',
                '"dimensions" must be an object such as {"language": ["en"]}.',
            ],
            'a string instead of a list of values' => [
                ['language' => 'de'],
                'In "dimensions", "language" must be a list with the values of a preset, e.g. {"language": ["en"]}.',
            ],
            'a value that is not a string' => [
                ['language' => [1]],
                'In "dimensions", the values of "language" must be non-empty strings',
            ],
            'a combination the site forbids' => [
                ['language' => ['fr'], 'country' => ['at']],
                'This site does not allow language "fr" together with country "at". Choose an allowed combination.',
            ],
            'a combination that is only forbidden through a default' => [
                ['language' => ['fr']],
                'This site does not allow language "fr" together with country "at". "country" was not named, so its default preset was used. Choose an allowed combination.',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider refusedDimensions
     * @param mixed $requested
     */
    public function dimensionsTheSiteDoesNotHaveAreRefused($requested, string $expectedMessage): void
    {
        $this->expectException(InvalidContentDimensionsException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->createResolver()->resolve($requested);
    }

    /**
     * @test
     */
    public function theMessageNamesTheArgumentThatWasChecked(): void
    {
        $this->expectExceptionMessage('"sourceDimensions" must be an object such as {"language": ["en"]}.');

        $this->createResolver()->resolve(['de'], 'sourceDimensions');
    }

    /**
     * @test
     */
    public function aSiteWithoutDimensionsAcceptsOnlyRequestsThatNameNone(): void
    {
        $resolver = $this->createResolver([]);

        self::assertSame([], $resolver->resolve([]));

        $this->expectException(InvalidContentDimensionsException::class);
        $this->expectExceptionMessage('This site has no content dimensions, but "dimensions" names "language". Omit "dimensions".');
        $resolver->resolve(['language' => ['de']]);
    }

    /**
     * @test
     */
    public function writingIntoASyncPresetIsRefused(): void
    {
        self::assertSame(
            'Not applied: language "fr" is a sync preset. It is re-translated automatically from language "de" each time a '
            . 'page is published there, so a change made in "fr" would be overwritten. Make the change in language "de" '
            . 'instead (dimensions {"language":["de"]}) and say that "fr" follows automatically once that page is published.',
            $this->createResolver()->findSyncPresetWriteRefusal(['language' => ['fr'], 'country' => ['ch']])
        );
    }

    /**
     * @return array<string, array{array<string, array<int, string>>}>
     */
    public static function writableDimensions(): array
    {
        return [
            'the default preset, although it says sync' => [['language' => ['de'], 'country' => ['at']]],
            'a preset that is translated once' => [['language' => ['en'], 'country' => ['at']]],
            'a preset without a strategy' => [['language' => ['de_CH', 'de'], 'country' => ['ch']]],
        ];
    }

    /**
     * @test
     * @dataProvider writableDimensions
     * @param array<string, array<int, string>> $dimensions
     */
    public function writingIntoOtherPresetsIsNotRefused(array $dimensions): void
    {
        self::assertNull($this->createResolver()->findSyncPresetWriteRefusal($dimensions));
    }

    /**
     * @test
     */
    public function theConfigurationIsDescribedForACallerThatHasToChooseADimension(): void
    {
        self::assertSame([
            'language' => [
                'label' => 'Language',
                'defaultPreset' => 'de',
                'presets' => [
                    'de' => ['label' => 'Deutsch', 'values' => ['de'], 'sync' => false],
                    'swiss' => ['label' => 'Schweizerdeutsch', 'values' => ['de_CH', 'de'], 'sync' => false],
                    'fr' => [
                        'label' => 'Français',
                        'values' => ['fr'],
                        'sync' => true,
                        'constraints' => ['country' => ['at' => false]],
                    ],
                    'en' => ['label' => 'English', 'values' => ['en'], 'sync' => false],
                ],
            ],
            'country' => [
                'label' => 'Country',
                'defaultPreset' => 'at',
                'presets' => [
                    'at' => ['label' => 'Österreich', 'values' => ['at'], 'sync' => false],
                    'ch' => ['label' => 'Schweiz', 'values' => ['ch'], 'sync' => false],
                ],
            ],
        ], $this->createResolver()->describeConfiguration());
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createResolver(array $configuration = self::CONFIGURATION): ContentDimensionResolver
    {
        $presetSource = new ConfigurationContentDimensionPresetSource();
        $presetSource->setConfiguration($configuration);

        $resolver = new ContentDimensionResolver();
        $property = new ReflectionProperty($resolver, 'contentDimensionPresetSource');
        $property->setAccessible(true);
        $property->setValue($resolver, $presetSource);

        return $resolver;
    }
}

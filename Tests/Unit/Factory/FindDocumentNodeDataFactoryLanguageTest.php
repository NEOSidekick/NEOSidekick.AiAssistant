<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The language a row is generated in is derived from the preset a variant belongs to, not from
 * the first of its stored dimension values: NodeData persists them sorted, so a variant of the
 * preset "sl: [sl, de]" that keeps its full fallback chain stores ["de", "sl"].
 */
class FindDocumentNodeDataFactoryLanguageTest extends TestCase
{
    /**
     * @var array<string, array{presets: array<string, array{values: array<string>}>}>
     */
    private const CONTENT_DIMENSIONS = [
        'language' => [
            'presets' => [
                'de' => ['values' => ['de']],
                'sl' => ['values' => ['sl', 'de']],
            ],
        ],
    ];

    private ReflectionMethod $resolveLanguageMethod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveLanguageMethod = new ReflectionMethod(FindDocumentNodeDataFactory::class, 'resolveLanguage');
        $this->resolveLanguageMethod->setAccessible(true);
    }

    /**
     * @param array<string, array<string>> $storedDimensionValues values as persisted on NodeData
     * @param array<string, mixed>         $contentDimensions
     */
    private function resolveLanguage(array $storedDimensionValues, array $contentDimensions, string $defaultLanguage = 'en'): string
    {
        $factory = new FindDocumentNodeDataFactory();
        ObjectAccess::setProperty($factory, 'languageDimensionName', 'language', true);
        ObjectAccess::setProperty($factory, 'contentDimensions', $contentDimensions, true);
        ObjectAccess::setProperty($factory, 'defaultLanguage', $defaultLanguage, true);

        $nodeData = $this->createMock(NodeData::class);
        $nodeData->method('getDimensionValues')->willReturn($storedDimensionValues);
        $node = $this->createMock(Node::class);
        $node->method('getNodeData')->willReturn($nodeData);

        return $this->resolveLanguageMethod->invoke($factory, $node);
    }

    /**
     * @test
     */
    public function itUsesThePrimaryValueOfThePresetAFullChainVariantBelongsTo(): void
    {
        // Stored sorted as ["de", "sl"] - taking the first stored value would yield "de".
        self::assertSame('sl', $this->resolveLanguage(['language' => ['de', 'sl']], self::CONTENT_DIMENSIONS));
    }

    /**
     * @test
     */
    public function itUsesThePrimaryValueOfASingleValueVariantsPreset(): void
    {
        self::assertSame('de', $this->resolveLanguage(['language' => ['de']], self::CONTENT_DIMENSIONS));
    }

    /**
     * @test
     */
    public function itUsesTheStoredValueWhenNoPresetMatches(): void
    {
        self::assertSame('fr', $this->resolveLanguage(['language' => ['fr']], self::CONTENT_DIMENSIONS));
    }

    /**
     * @test
     */
    public function itFallsBackToTheConfiguredDefaultLanguageWithoutContentDimensions(): void
    {
        self::assertSame('en', $this->resolveLanguage([], []));
        self::assertSame('it', $this->resolveLanguage([], [], 'it'));
    }
}

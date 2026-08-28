<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Resources;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The consent screen is the only page a Neos editor ever sees from this package, so a translated
 * catalogue that silently lost a unit would show a raw label. Every translated Agent catalogue must
 * therefore carry the full set of ids the English source defines.
 */
class TranslationCatalogTest extends TestCase
{
    private const TRANSLATIONS_PATH = __DIR__ . '/../../../Resources/Private/Translations';

    /**
     * @return array<string, array{0: string}>
     */
    public static function translatedLanguageProvider(): array
    {
        return [
            'german' => ['de'],
            'french' => ['fr'],
        ];
    }

    /**
     * @test
     * @dataProvider translatedLanguageProvider
     */
    public function everyTranslatedAgentCatalogueCarriesEveryEnglishTransUnitId(string $language): void
    {
        $expectedIds = $this->readTransUnitIds('en');
        self::assertNotEmpty($expectedIds, 'the English Agent catalogue is the reference for the consent screen');

        $actualIds = $this->readTransUnitIds($language);

        self::assertEqualsCanonicalizing(
            $expectedIds,
            $actualIds,
            sprintf('the %s Agent catalogue must carry exactly the ids of the English one', $language)
        );
    }

    /**
     * @test
     * @dataProvider translatedLanguageProvider
     */
    public function everyTranslatedAgentUnitHasANonEmptyTranslation(string $language): void
    {
        foreach ($this->loadTransUnits($language) as $id => $translation) {
            self::assertNotSame('', trim($translation), sprintf('%s:%s is empty', $language, $id));
        }
    }

    /**
     * The placeholders are positional, so a dropped `{0}` would render a sentence missing the
     * client name, the editor name or the redirect target.
     *
     * @test
     * @dataProvider translatedLanguageProvider
     */
    public function everyTranslatedAgentUnitKeepsThePlaceholdersOfItsEnglishSource(string $language): void
    {
        $sources = $this->loadTransUnits('en');

        foreach ($this->loadTransUnits($language) as $id => $translation) {
            self::assertSame(
                $this->extractPlaceholders($sources[$id]),
                $this->extractPlaceholders($translation),
                sprintf('%s:%s must keep the placeholders of the English source', $language, $id)
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function readTransUnitIds(string $language): array
    {
        return array_keys($this->loadTransUnits($language));
    }

    /**
     * The English catalogue is a source-only file, every translated one carries `target` elements,
     * so the rendered text is whichever of the two the file defines last.
     *
     * @return array<string, string>
     */
    private function loadTransUnits(string $language): array
    {
        $path = sprintf('%s/%s/Agent.xlf', self::TRANSLATIONS_PATH, $language);
        self::assertFileExists($path);

        $document = new DOMDocument();
        self::assertTrue($document->load($path), sprintf('%s is not well-formed XML', $path));

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xliff', 'urn:oasis:names:tc:xliff:document:1.2');

        $units = [];
        foreach ($xpath->query('//xliff:trans-unit') as $unit) {
            $id = $unit->getAttribute('id');
            self::assertNotSame('', $id, sprintf('a trans-unit in %s has no id', $path));
            self::assertArrayNotHasKey($id, $units, sprintf('%s defines the id %s twice', $path, $id));

            $targets = $xpath->query('./xliff:target', $unit);
            $texts = $targets->length > 0 ? $targets : $xpath->query('./xliff:source', $unit);
            self::assertGreaterThan(0, $texts->length, sprintf('%s:%s has no text', $path, $id));

            $units[$id] = (string)$texts->item(0)->textContent;
        }

        return $units;
    }

    /**
     * @return array<int, string>
     */
    private function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{\d+\}/', $text, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}

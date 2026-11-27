<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ConfigurationContentDimensionPresetSource;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Exception\InvalidContentDimensionsException;
use NEOSidekick\AiAssistant\Service\ContentDimensionResolver;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Service\NodeTreeExtractor;
use NEOSidekick\AiAssistant\Service\SearchNodesExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Every service of the agent API takes its dimensions through the ContentDimensionResolver: a
 * named preset is completed to its fallback chain, dimensions that are not named get the default
 * preset, and dimensions the site does not have are refused before anything is read or written.
 *
 * `/page` with a text exists in English only. `de` is the default language, `en_UK` falls back
 * to `en`. The resolver gets this configuration from the test itself, because the presets of the
 * `language` dimension differ between the distributions these tests run in.
 */
class ContentDimensionValidationTest extends FunctionalTestCase
{
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const ENGLISH = ['language' => ['en']];
    private const BRITISH = ['language' => ['en_UK', 'en']];
    private const CONTENT_DIMENSIONS = [
        'language' => [
            // an XLIFF id, as site packages use them for labels
            'label' => 'Neos.Neos:Main:content',
            'default' => 'de',
            'defaultPreset' => 'de',
            'presets' => [
                'de' => ['label' => 'Deutsch', 'values' => ['de']],
                'en' => ['label' => 'English', 'values' => ['en']],
                'en_UK' => ['label' => 'English (UK)', 'values' => ['en_UK', 'en']],
                // a preset the site unset with `~`
                'all' => null,
            ],
        ],
    ];

    protected array $dimensions = ['en', 'en_UK'];
    protected array $siteHosts = ['example.com'];

    private string $pageId = '';
    private string $textId = '';
    private ?ContentDimensionPresetSourceInterface $originalPresetSource = null;

    public function setUp(): void
    {
        parent::setUp();

        $englishContext = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => self::ENGLISH]);
        $englishSiteNode = $this->rootNode->getNode('/sites/example')->createVariantForContext($englishContext);

        $page = $englishSiteNode->createNode('page', $this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:RestrictedPage'));
        $page->setProperty('title', 'Page');
        $text = $page->getNode('main')->createNode('text', $this->nodeTypeManager->getNodeType(self::TEXT_NODE_TYPE));
        $text->setProperty('text', 'colour and flavour');

        $this->pageId = $page->getIdentifier();
        $this->textId = $text->getIdentifier();

        $this->resetContentRepositoryState();

        $this->originalPresetSource = $this->objectManager->get(ContentDimensionPresetSourceInterface::class);
        $this->configureContentDimensions(self::CONTENT_DIMENSIONS);
    }

    public function tearDown(): void
    {
        if ($this->originalPresetSource !== null) {
            $this->inject($this->objectManager->get(ContentDimensionResolver::class), 'contentDimensionPresetSource', $this->originalPresetSource);
            $this->originalPresetSource = null;
        }
        parent::tearDown();
    }

    /**
     * @test
     */
    public function aPatchInAnUnknownLanguageIsRefusedWithoutWriting(): void
    {
        $result = $this->applyPatches(
            [['operation' => 'updateNode', 'nodeId' => $this->textId, 'properties' => ['text' => 'español']]],
            ['language' => ['es']]
        );

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isRollbackPerformed(), 'Refused before anything is written');
        $this->assertStringContainsString('["es"] is not a configured preset of the "language" dimension', $result->getError()->getMessage());
        $this->resetContentRepositoryState();
        $this->assertSame('colour and flavour', $this->getNode($this->textId, self::ENGLISH)->getProperty('text'));
    }

    /**
     * Without its fallback chain, `en_UK` would not show the English page at all and the patch
     * would fail with "node not found". With it, the page is found and recognised as a fallback.
     *
     * @test
     */
    public function aPresetNamedByItsFirstValueIsCompletedToItsFallbackChain(): void
    {
        $result = $this->applyPatches(
            [['operation' => 'updateNode', 'nodeId' => $this->textId, 'properties' => ['text' => 'british']]],
            ['language' => ['en_UK']]
        );

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('is a dimension fallback', $result->getError()->getMessage());
    }

    /**
     * @test
     */
    public function theSourceDimensionsOfAVariantAreCheckedAsWell(): void
    {
        $result = $this->applyPatches(
            [['operation' => 'createVariant', 'nodeId' => $this->pageId, 'copyContent' => true]],
            ['language' => ['de']],
            ['language' => ['es']]
        );

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('["es"] is not a configured preset of the "language" dimension', $result->getError()->getMessage());
        $this->resetContentRepositoryState();
        $this->assertNull($this->getNode($this->pageId, ['language' => ['de']]));
    }

    /**
     * @test
     */
    public function aPatchInASyncLanguageIsRefusedWithoutWriting(): void
    {
        $this->markEnglishAsSyncLanguage();

        $result = $this->applyPatches(
            [['operation' => 'updateNode', 'nodeId' => $this->textId, 'properties' => ['text' => 'changed']]],
            self::ENGLISH
        );

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isRollbackPerformed(), 'Refused before anything is written');
        $this->assertStringContainsString('Not applied: language "en" is a sync preset.', $result->getError()->getMessage());
        $this->assertStringContainsString('Make the change in language "de" instead (dimensions {"language":["de"]})', $result->getError()->getMessage());
        $this->resetContentRepositoryState();
        $this->assertSame('colour and flavour', $this->getNode($this->textId, self::ENGLISH)->getProperty('text'));
    }

    /**
     * @test
     */
    public function aPageIsReadInTheCompletedDimensionsAndTheResultNamesThem(): void
    {
        $tree = $this->objectManager->get(NodeTreeExtractor::class)->extract($this->pageId, $this->currentUserWorkspace, ['language' => ['en_UK']]);

        $this->assertSame(self::BRITISH, $tree['dimensions']);
        $this->assertSame($this->pageId, $tree['rootNode']['id'], 'The English page shows through in en_UK');
    }

    /**
     * @test
     */
    public function readingAPageInAnUnknownLanguageIsRefused(): void
    {
        $this->expectException(InvalidContentDimensionsException::class);
        $this->expectExceptionMessage('["es"] is not a configured preset of the "language" dimension');

        $this->objectManager->get(NodeTreeExtractor::class)->extract($this->pageId, $this->currentUserWorkspace, ['language' => ['es']]);
    }

    /**
     * @test
     */
    public function theDocumentListNamesTheDefaultDimensionsWhenNoneWereRequested(): void
    {
        $result = $this->objectManager->get(DocumentNodeListExtractor::class)->extract(
            workspace: $this->currentUserWorkspace,
            siteNodeName: 'example'
        );

        $this->assertSame(['language' => ['de']], $result['dimensions']);
        $this->assertArrayNotHasKey('contentDimensions', $result);
    }

    /**
     * @test
     */
    public function theDocumentListDescribesTheContentDimensionsOnRequest(): void
    {
        $result = $this->objectManager->get(DocumentNodeListExtractor::class)->extract(
            workspace: $this->currentUserWorkspace,
            dimensions: self::ENGLISH,
            siteNodeName: 'example',
            includeContentDimensions: true
        );

        $language = $result['contentDimensions']['language'];
        // "Content" or its translation, depending on the default locale of the distribution
        $this->assertStringNotContainsString(':', $language['label'], 'A label given as XLIFF id is translated');
        $this->assertSame('de', $language['defaultPreset']);
        $this->assertSame(['en_UK', 'en'], $language['presets']['en_UK']['values']);
        $this->assertSame('English (UK)', $language['presets']['en_UK']['label']);
        $this->assertFalse($language['presets']['en']['sync']);
        $this->assertArrayNotHasKey('all', $language['presets'], 'A preset the site unset is not offered');
    }

    /**
     * @test
     */
    public function aSearchStaysInTheRequestedLanguage(): void
    {
        $extractor = $this->objectManager->get(SearchNodesExtractor::class);

        $english = $extractor->search('flavour', $this->currentUserWorkspace, self::ENGLISH);
        $this->assertSame(self::ENGLISH, $english['dimensions']);
        $this->assertSame(1, $english['resultCount']);

        $default = $extractor->search('flavour', $this->currentUserWorkspace);
        $this->assertSame(['language' => ['de']], $default['dimensions']);
        $this->assertSame(0, $default['resultCount'], 'The text exists in English only');
    }

    /**
     * @test
     */
    public function aSearchInAnUnknownLanguageIsRefused(): void
    {
        $this->expectException(InvalidContentDimensionsException::class);

        $this->objectManager->get(SearchNodesExtractor::class)->search('flavour', $this->currentUserWorkspace, ['language' => ['es']]);
    }

    /**
     * Sitegeist.LostInTranslation is configured with `translationStrategy: sync` on a preset.
     */
    private function markEnglishAsSyncLanguage(): void
    {
        $configuration = self::CONTENT_DIMENSIONS;
        $configuration['language']['presets']['en']['options']['translationStrategy'] = 'sync';
        $this->configureContentDimensions($configuration);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function configureContentDimensions(array $configuration): void
    {
        $presetSource = new ConfigurationContentDimensionPresetSource();
        $presetSource->setConfiguration($configuration);

        $this->inject($this->objectManager->get(ContentDimensionResolver::class), 'contentDimensionPresetSource', $presetSource);
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     * @param array<string, array<int, string>> $dimensions
     * @param array<string, array<int, string>> $sourceDimensions
     */
    private function applyPatches(array $patches, array $dimensions, array $sourceDimensions = []): PatchResult
    {
        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            $dimensions,
            $sourceDimensions
        );
    }

    /**
     * @param array<string, array<int, string>> $dimensions
     */
    private function getNode(string $nodeId, array $dimensions): ?NodeInterface
    {
        return $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => $dimensions,
            'targetDimensions' => array_map(static fn(array $values): string => $values[0], $dimensions),
            'invisibleContentShown' => true,
        ])->getNodeByIdentifier($nodeId);
    }

    private function resetContentRepositoryState(): void
    {
        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }
}

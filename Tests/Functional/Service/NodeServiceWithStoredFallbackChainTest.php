<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The flagship case of the language filter: a variant that STORES its preset's full fallback
 * chain on its NodeData — a Slovenian page of the preset "sl: [sl, de]" persisting ["sl", "de"]
 * (sorted to ["de", "sl"]). Legacy content and contexts with multi-value target dimensions
 * produce exactly this shape.
 *
 * The SQL dimension constraint can only match variants whose values CONTAIN one of the selected
 * ones, so such a variant satisfies a "de"-only query on the database level and used to leak into
 * the German selection. The first assertion below therefore FAILS on the old contains-matching
 * logic — that is the regression this test guards.
 *
 * The sibling test {@see NodeServiceWithLanguageFallbackChainTest} covers the other storage shape:
 * createVariantForContext persists only the TARGET value (["sl"]), which the old logic already
 * handled, so its assertions alone do not pin the fix down.
 */
class NodeServiceWithStoredFallbackChainTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    /**
     * Mirrors a customer setup where Slovenian falls back to German.
     */
    private const CONTENT_DIMENSIONS = [
        'language' => [
            'default' => 'de',
            'defaultPreset' => 'de',
            'presets' => [
                'de' => ['label' => 'Deutsch', 'values' => ['de'], 'uriSegment' => 'de'],
                'sl' => ['label' => 'Slovenščina', 'values' => ['sl', 'de'], 'uriSegment' => 'sl'],
            ],
        ],
    ];

    private const PAGE_WITH_SLOVENIAN_VARIANT = '/sites/example/node-wan-kenodi';

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg']);

        $this->createVariantStoringTheFullFallbackChain($page1->getNodeData(), ['sl', 'de']);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    public function tearDown(): void
    {
        // Restore the real dimension configuration, in case the NodeService is configured
        // as a singleton in the distribution running these tests.
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $originalContentDimensions = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepository.contentDimensions'
        );
        $this->inject($this->objectManager->get(NodeService::class), 'contentDimensions', $originalContentDimensions);

        parent::tearDown();
    }

    /**
     * Mirrors {@see \Neos\ContentRepository\Domain\Model\Node::createVariantForContext}, but stores
     * the preset's FULL fallback chain instead of the context's target dimension values — the very
     * shape createVariantForContext can never produce.
     *
     * @param array<string> $languageDimensionValues
     */
    private function createVariantStoringTheFullFallbackChain(NodeData $originNodeData, array $languageDimensionValues): void
    {
        $variantNodeData = new NodeData(
            $originNodeData->getPath(),
            $this->liveWorkspace,
            $originNodeData->getIdentifier(),
            ['language' => $languageDimensionValues]
        );
        $variantNodeData->similarize($originNodeData);
        // similarize() writes properties, which already registers the new NodeData via
        // NodeData::addOrUpdate() - adding it again would throw a KnownObjectException.
        if ($this->persistenceManager->isNewObject($variantNodeData)) {
            $this->nodeDataRepository->add($variantNodeData);
        }
        $this->persistenceManager->persistAll();
    }

    private function getNodeServiceWithFallbackChainDimensions(): NodeService
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeService, 'languageDimensionName', 'language');

        return $nodeService;
    }

    /**
     * The language dimension values of every returned row for the given node path, each sorted so
     * that the assertion does not depend on the serialization order of the context path.
     *
     * @param array<string, mixed> $foundNodes keyed by context path
     *
     * @return array<array<string>>
     */
    private function getLanguageDimensionValuesFor(array $foundNodes, string $nodePath): array
    {
        $languageDimensionValues = [];
        foreach (array_keys($foundNodes) as $contextPath) {
            $contextPathSegments = NodePaths::explodeContextPath($contextPath);
            if ($contextPathSegments['nodePath'] !== $nodePath) {
                continue;
            }
            $values = $contextPathSegments['dimensions']['language'] ?? [];
            sort($values);
            $languageDimensionValues[] = $values;
        }

        return $languageDimensionValues;
    }

    private function findWithLanguageFilter(string $languageDimensionFilter): array
    {
        return $this->getNodeServiceWithFallbackChainDimensions()->find(
            new FindDocumentNodesFilter(filter: 'custom', workspace: 'live', languageDimensionFilter: $languageDimensionFilter),
            $this->createControllerContextForDomain('example.com')
        );
    }

    /**
     * @test
     */
    public function itDoesNotLeakAVariantStoringItsFullFallbackChainIntoTheBaseLanguageSelection(): void
    {
        $foundNodes = $this->findWithLanguageFilter('de');

        $this->assertSame(
            [['de']],
            $this->getLanguageDimensionValuesFor($foundNodes, self::PAGE_WITH_SLOVENIAN_VARIANT),
            'Only the German variant of the page may be returned when filtering for "de"'
        );
        $this->assertCount(3, $foundNodes, 'The site node and both German pages, and nothing else');
    }

    /**
     * @test
     */
    public function itFindsAVariantStoringItsFullFallbackChainWhenFilteringForItsOwnLanguage(): void
    {
        $foundNodes = $this->findWithLanguageFilter('sl');

        $this->assertSame(
            [['de', 'sl']],
            $this->getLanguageDimensionValuesFor($foundNodes, self::PAGE_WITH_SLOVENIAN_VARIANT),
            'The Slovenian variant must be returned when filtering for "sl"'
        );
        $this->assertCount(1, $foundNodes, 'Only the single Slovenian variant exists');
    }
}

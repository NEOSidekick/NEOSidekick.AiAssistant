<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The language each row is generated in, end to end through NodeService::find().
 *
 * NodeData persists dimension values SORTED, so a variant of the preset "sl: [sl, de]" that keeps
 * its full fallback chain stores ["de", "sl"] — taking its first stored value would generate
 * German content for a Slovenian page. The preset's configured primary value is authoritative.
 */
class NodeServiceGenerationLanguageTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

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

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg']);

        // A Slovenian variant that stores its preset's FULL fallback chain
        $page1NodeData = $page1->getNodeData();
        $slovenianNodeData = new NodeData(
            $page1NodeData->getPath(),
            $this->liveWorkspace,
            $page1NodeData->getIdentifier(),
            ['language' => ['sl', 'de']]
        );
        $slovenianNodeData->similarize($page1NodeData);
        // similarize() writes properties, which already registers the new NodeData via
        // NodeData::addOrUpdate() - adding it again would throw a KnownObjectException.
        if ($this->persistenceManager->isNewObject($slovenianNodeData)) {
            $this->nodeDataRepository->add($slovenianNodeData);
        }
        $this->persistenceManager->persistAll();

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    public function tearDown(): void
    {
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $originalContentDimensions = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepository.contentDimensions'
        );
        $originalLanguageDimensionName = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'NEOSidekick.AiAssistant.languageDimensionName'
        );
        // The factory IS a singleton, so everything injected into it must be restored here.
        $factory = $this->objectManager->get(FindDocumentNodeDataFactory::class);
        $this->inject($factory, 'contentDimensions', $originalContentDimensions);
        $this->inject($factory, 'languageDimensionName', $originalLanguageDimensionName);
        $this->inject($this->objectManager->get(NodeService::class), 'contentDimensions', $originalContentDimensions);

        parent::tearDown();
    }

    /**
     * @return array<string, FindDocumentNodeData>
     */
    private function findWithLanguageFilter(string $languageDimensionFilter): array
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeService, 'languageDimensionName', 'language');
        $factory = $this->objectManager->get(FindDocumentNodeDataFactory::class);
        $this->inject($factory, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($factory, 'languageDimensionName', 'language');

        return $nodeService->find(
            new FindDocumentNodesFilter(filter: 'custom', workspace: 'live', languageDimensionFilter: $languageDimensionFilter),
            $this->createControllerContextForDomain('example.com')
        );
    }

    /**
     * @param array<string, FindDocumentNodeData> $foundNodes
     *
     * @return array<string>
     */
    private function getLanguagesOf(array $foundNodes): array
    {
        return array_values(array_map(
            static fn(FindDocumentNodeData $findDocumentNodeData): string => $findDocumentNodeData->getLanguage(),
            $foundNodes
        ));
    }

    /**
     * @test
     */
    public function itGeneratesAFullChainVariantInItsOwnLanguage(): void
    {
        $foundNodes = $this->findWithLanguageFilter('sl');

        $this->assertCount(1, $foundNodes);
        $this->assertSame(['sl'], $this->getLanguagesOf($foundNodes));
    }

    /**
     * @test
     */
    public function itGeneratesTheBaseLanguageVariantsInTheBaseLanguage(): void
    {
        $foundNodes = $this->findWithLanguageFilter('de');

        $this->assertSame(['de', 'de'], $this->getLanguagesOf($foundNodes), 'The site node and the German page');
    }
}

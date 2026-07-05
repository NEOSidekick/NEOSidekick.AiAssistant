<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Covers the language dimension filter for sites whose secondary languages use
 * fallback chains (e.g. "sl" falling back to "de"). Variants of such languages
 * store the full chain ["sl", "de"] on their NodeData, so a naive "contains"
 * comparison would wrongly match them when filtering for the base language only.
 */
class NodeServiceWithLanguageFallbackChainTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    /**
     * Dimension configuration injected into the NodeService for these tests:
     * mirrors a customer setup where Slovenian falls back to German.
     */
    private const CONTENT_DIMENSIONS = [
        'language' => [
            'default' => 'de',
            'defaultPreset' => 'de',
            'presets' => [
                'de' => ['label' => 'Deutsch', 'values' => ['de'], 'uriSegment' => 'de'],
                'en' => ['label' => 'English', 'values' => ['en'], 'uriSegment' => 'en'],
                'sl' => ['label' => 'Slovenščina', 'values' => ['sl', 'de'], 'uriSegment' => 'sl'],
            ],
        ],
    ];

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg']);

        // Create Slovenian variants (fallback chain ["sl", "de"]) for the site and page 1.
        // Page 2 stays German-only.
        $slovenianContext = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => ['language' => ['sl', 'de']],
            'targetDimensions' => ['language' => 'sl'],
        ]);
        $exampleSiteNode->createVariantForContext($slovenianContext);
        $page1->createVariantForContext($slovenianContext);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    public function tearDown(): void
    {
        // Restore the real dimension configuration on the NodeService singleton.
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $originalContentDimensions = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepository.contentDimensions'
        );
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'contentDimensions', $originalContentDimensions);

        parent::tearDown();
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
     * createVariantForContext persists the TARGET dimension values of the context,
     * so the Slovenian variants store just ["sl"] — like variants created through
     * the Neos UI or Sitegeist.LostInTranslation. The preset's full fallback chain
     * ["sl", "de"] only lives in the configuration.
     *
     * @return array<string>
     */
    private function getStoredSlovenianDimensionValues(): array
    {
        return ['sl'];
    }

    /**
     * @test
     */
    public function itDoesNotFindFallbackChainVariantsWhenFilteringForTheBaseLanguage(): void
    {
        $nodeService = $this->getNodeServiceWithFallbackChainDimensions();
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: 'de');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $germanDimensions = ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')];
        $slovenianDimensions = ['language' => $this->getStoredSlovenianDimensionValues()];
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, $germanDimensions), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, $germanDimensions), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-mc-nodeface', $this->currentUserWorkspace, $germanDimensions), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, $slovenianDimensions), $foundNodes);
        $this->assertArrayNotHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, $slovenianDimensions), $foundNodes);
        $this->assertCount(3, $foundNodes, 'Only the three German variants may be returned when filtering for "de"');
    }

    /**
     * @test
     */
    public function itFindsOnlyFallbackChainVariantsWhenFilteringForThatLanguage(): void
    {
        $nodeService = $this->getNodeServiceWithFallbackChainDimensions();
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace, languageDimensionFilter: 'sl');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $slovenianDimensions = ['language' => $this->getStoredSlovenianDimensionValues()];
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', $this->currentUserWorkspace, $slovenianDimensions), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example/node-wan-kenodi', $this->currentUserWorkspace, $slovenianDimensions), $foundNodes);
        $this->assertCount(2, $foundNodes, 'Only the two Slovenian variants may be returned when filtering for "sl"');
    }

    /**
     * @test
     */
    public function itFindsAllVariantsWithoutLanguageFilter(): void
    {
        $nodeService = $this->getNodeServiceWithFallbackChainDimensions();
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: $this->currentUserWorkspace);
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertCount(5, $foundNodes, 'Three German variants plus two Slovenian variants must be returned without a language filter');
    }
}

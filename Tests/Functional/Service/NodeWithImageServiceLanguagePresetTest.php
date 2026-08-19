<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Service\NodeWithImageService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The image module runs a second query for the content nodes carrying the images. It has to
 * translate the selected preset IDENTIFIERS into their configured dimension VALUES just like the
 * document query does — otherwise a preset whose identifier differs from its values (here:
 * "german" → ["de"]) finds documents but no images, and the module returns nothing at all.
 */
class NodeWithImageServiceLanguagePresetTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    /**
     * Preset identifier deliberately different from the dimension value it selects.
     */
    private const CONTENT_DIMENSIONS = [
        'language' => [
            'default' => 'de',
            'defaultPreset' => 'german',
            'presets' => [
                'german' => ['label' => 'Deutsch', 'values' => ['de'], 'uriSegment' => 'de'],
            ],
        ],
    ];

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg', 'image2.jpg']);
        $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg']);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    public function tearDown(): void
    {
        // NodeWithImageService is a singleton, so EVERYTHING injected into it has to be put back;
        // the NodeService restore is defensive in case a distribution configures it as one too.
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $originalContentDimensions = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepository.contentDimensions'
        );
        $originalLanguageDimensionName = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'NEOSidekick.AiAssistant.languageDimensionName'
        );
        $this->inject($this->objectManager->get(NodeService::class), 'contentDimensions', $originalContentDimensions);
        $nodeWithImageService = $this->objectManager->get(NodeWithImageService::class);
        $this->inject($nodeWithImageService, 'contentDimensions', $originalContentDimensions);
        $this->inject($nodeWithImageService, 'languageDimensionName', $originalLanguageDimensionName);

        parent::tearDown();
    }

    /**
     * @test
     */
    public function itFindsDocumentsWithTheirImagesWhenFilteringByAPresetIdentifierThatIsNotADimensionValue(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        /** @var NodeWithImageService $nodeWithImageService */
        $nodeWithImageService = $this->objectManager->get(NodeWithImageService::class);
        $this->inject($nodeService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeService, 'languageDimensionName', 'language');
        $this->inject($nodeWithImageService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeWithImageService, 'languageDimensionName', 'language');

        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'custom',
            workspace: 'live',
            languageDimensionFilter: 'german'
        );

        $documentNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);
        $this->assertNotEmpty($documentNodes, 'The document query must find the German pages');

        $documentNodesWithImages = $nodeWithImageService->findDocumentNodesHavingChildNodesWithImages(
            $findDocumentNodesFilter,
            $documentNodes,
            $controllerContext
        );

        $germanDimensions = ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')];
        $page1ContextPath = NodePaths::generateContextPath('/sites/example/node-wan-kenodi', 'live', $germanDimensions);
        $page2ContextPath = NodePaths::generateContextPath('/sites/example/node-mc-nodeface', 'live', $germanDimensions);

        $this->assertArrayHasKey($page1ContextPath, $documentNodesWithImages);
        $this->assertArrayHasKey($page2ContextPath, $documentNodesWithImages);
        $this->assertCount(2, $documentNodesWithImages, 'Only the two pages carrying images may be returned');

        /** @var FindDocumentNodeData $page1 */
        $page1 = $documentNodesWithImages[$page1ContextPath];
        $this->assertCount(2, $page1->getImages());
        /** @var FindDocumentNodeData $page2 */
        $page2 = $documentNodesWithImages[$page2ContextPath];
        $this->assertCount(1, $page2->getImages());
    }
}

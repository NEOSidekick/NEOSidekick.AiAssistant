<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
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
                // Cross-language fallback chains: the generation language of a localized image
                // must not be taken from its German container document.
                'it' => ['label' => 'Italiano', 'values' => ['it', 'de'], 'uriSegment' => 'it'],
                'fr' => ['label' => 'Français', 'values' => ['fr', 'de'], 'uriSegment' => 'fr'],
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
        $factory = $this->objectManager->get(FindDocumentNodeDataFactory::class);
        $this->inject($factory, 'contentDimensions', $originalContentDimensions);
        $this->inject($factory, 'languageDimensionName', $originalLanguageDimensionName);

        parent::tearDown();
    }

    /**
     * @return array{0: NodeService, 1: NodeWithImageService}
     */
    private function servicesWithTestDimensions(): array
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        /** @var NodeWithImageService $nodeWithImageService */
        $nodeWithImageService = $this->objectManager->get(NodeWithImageService::class);
        $this->inject($nodeService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeService, 'languageDimensionName', 'language');
        $this->inject($nodeWithImageService, 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($nodeWithImageService, 'languageDimensionName', 'language');
        $this->inject($this->objectManager->get(FindDocumentNodeDataFactory::class), 'contentDimensions', self::CONTENT_DIMENSIONS);
        $this->inject($this->objectManager->get(FindDocumentNodeDataFactory::class), 'languageDimensionName', 'language');

        return [$nodeService, $nodeWithImageService];
    }

    /**
     * Varies a single IMAGE node into another dimension, leaving its document a shine-through
     * fallback - the shape an editor produces by localizing one element on an inherited page.
     *
     * @param array<string> $contextDimensionValues
     */
    private function localizeImageNode(string $imageNodePath, array $contextDimensionValues, string $targetDimensionValue): void
    {
        $germanContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['de']],
            'targetDimensions' => ['language' => 'de'],
        ]);
        $variantContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => $contextDimensionValues],
            'targetDimensions' => ['language' => $targetDimensionValue],
        ]);
        $germanContext->getNode($imageNodePath)->createVariantForContext($variantContext);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function itFindsDocumentsWithTheirImagesWhenFilteringByAPresetIdentifierThatIsNotADimensionValue(): void
    {
        [$nodeService, $nodeWithImageService] = $this->servicesWithTestDimensions();

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

    /**
     * A page that only shines through into the selected language, holding ONE image node that was
     * localized into it. The document is not an editable row, but the image is - so the page has
     * to appear as its container, and the row's generation language has to follow the image.
     *
     * @test
     */
    public function itFindsShineThroughDocumentsAsContainersForLocalizedImages(): void
    {
        $this->localizeImageNode('/sites/example/node-wan-kenodi/main/image-image1', ['it', 'de'], 'it');
        [$nodeService, $nodeWithImageService] = $this->servicesWithTestDimensions();

        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'live', languageDimensionFilter: 'it');

        $documentNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);
        $this->assertEmpty($documentNodes, 'No document exists in Italian - only a single image was localized');

        $documentNodesWithImages = $nodeWithImageService->findDocumentNodesHavingChildNodesWithImages(
            $findDocumentNodesFilter,
            $documentNodes,
            $controllerContext
        );

        $this->assertCount(1, $documentNodesWithImages, 'The shine-through page must appear as container row');
        /** @var FindDocumentNodeData $row */
        $row = reset($documentNodesWithImages);
        $this->assertCount(1, $row->getImages(), 'Only the localized image may be attached, not the two German ones');
        $this->assertSame('it', $row->getLanguage(), 'The row language must come from the image node, not from its German document');

        // The container row must not have materialized a document variant.
        $italianContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['it', 'de']],
            'targetDimensions' => ['language' => 'it'],
        ]);
        $this->assertSame(
            ['de'],
            $italianContext->getNode('/sites/example/node-wan-kenodi')->getNodeData()->getDimensionValues()['language'],
            'Listing a shine-through document must not materialize it'
        );
    }

    /**
     * Container rows are built inside the image module instead of being taken from the document
     * list, so the document node type filter - the editor's "Restrict to page type" - has to be
     * applied to them explicitly.
     *
     * @test
     */
    public function itAppliesTheDocumentNodeTypeFilterToContainerRows(): void
    {
        $this->localizeImageNode('/sites/example/node-wan-kenodi/main/image-image1', ['it', 'de'], 'it');
        [, $nodeWithImageService] = $this->servicesWithTestDimensions();
        $controllerContext = $this->createControllerContextForDomain('example.com');

        $matchingNodeType = $nodeWithImageService->findDocumentNodesHavingChildNodesWithImages(
            new FindDocumentNodesFilter(
                filter: 'custom',
                workspace: 'live',
                languageDimensionFilter: 'it',
                nodeTypeFilter: 'NEOSidekick.AiAssistant.Testing:Page'
            ),
            [],
            $controllerContext
        );
        $this->assertCount(1, $matchingNodeType, 'The container page is of the filtered node type');

        $otherNodeType = $nodeWithImageService->findDocumentNodesHavingChildNodesWithImages(
            new FindDocumentNodesFilter(
                filter: 'custom',
                workspace: 'live',
                languageDimensionFilter: 'it',
                nodeTypeFilter: 'NEOSidekick.AiAssistant.Testing:HomePage'
            ),
            [],
            $controllerContext
        );
        $this->assertEmpty($otherNodeType, 'A container row must not bypass the document node type filter');
    }

    /**
     * @test
     */
    public function itKeepsOneContainerRowPerLocalizedDimension(): void
    {
        $this->localizeImageNode('/sites/example/node-wan-kenodi/main/image-image1', ['it', 'de'], 'it');
        $this->localizeImageNode('/sites/example/node-wan-kenodi/main/image-image2', ['fr', 'de'], 'fr');
        [, $nodeWithImageService] = $this->servicesWithTestDimensions();

        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'live', languageDimensionFilter: 'it,fr');

        $documentNodesWithImages = $nodeWithImageService->findDocumentNodesHavingChildNodesWithImages(
            $findDocumentNodesFilter,
            [],
            $controllerContext
        );

        $this->assertCount(2, $documentNodesWithImages, 'One container row per localized dimension');
        $languages = array_map(
            static fn(FindDocumentNodeData $row): string => $row->getLanguage(),
            array_values($documentNodesWithImages)
        );
        sort($languages);
        $this->assertSame(['fr', 'it'], $languages);
    }
}

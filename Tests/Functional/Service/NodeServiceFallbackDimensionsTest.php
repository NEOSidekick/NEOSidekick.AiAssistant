<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeFindingService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Fallback pages (a preset serving another preset's content through its fallback chain,
 * e.g. en_UK -> en) are NOT editable rows:
 * - findImportantPages() re-addresses fallback URLs to their origin dimension (deduped),
 * - real variants keep their own dimension,
 * - updatePropertiesOnNodes() rejects writes to fallback context paths: the old CR's
 *   implicit copy-on-write on setProperty() would materialize a variant as a save side
 *   effect and permanently detach the page from its fallback chain. Variant creation
 *   must remain an explicit editor decision in the Neos UI.
 */
class NodeServiceFallbackDimensionsTest extends FunctionalTestCase
{
    private const FALLBACK_PAGE_PATH = '/sites/example/fallback-page';
    private const FALLBACK_PAGE_IMAGE_PATH = '/sites/example/fallback-page/main/image-image1';

    protected array $dimensions = ['en', 'en_UK'];
    protected array $siteHosts = ['example.com'];

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');

        $englishContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en']]
        ]);
        $englishSiteNode = $exampleSiteNode->createVariantForContext($englishContext);

        // A page existing ONLY in en - served in en_UK through the fallback chain
        $this->createPageWithImageNodes($englishSiteNode, 'fallback-page', 'Fallback Page', ['image1.jpg']);

        // A page with a REAL en_UK variant
        $variantPage = $this->createPageWithImageNodes($englishSiteNode, 'variant-page', 'Variant Page', ['image1.jpg']);
        $ukContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en_UK', 'en']],
            'targetDimensions' => ['language' => 'en_UK'],
        ]);
        $variantPage->createVariantForContext($ukContext);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    private function findImportantPagesForCandidates(array $candidateUrls): array
    {
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();
        $apiFacadeMock
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->willReturn($candidateUrls);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            languageDimensionFilter: 'en,en_UK'
        );
        $controllerContext = $this->createControllerContextForDomain('example.com');

        return $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
    }

    private function getDefaultUriSuffix(): string
    {
        /** @var NodeFindingService $nodeFindingService */
        $nodeFindingService = $this->objectManager->get(NodeFindingService::class);
        $routesConfiguration = ObjectAccess::getProperty($nodeFindingService, 'routesConfiguration', true);

        return $routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '';
    }

    /**
     * @test
     */
    public function importantPagesReAddressesFallbackUrlsToTheirOrigin(): void
    {
        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/uk/fallback-page' . $this->getDefaultUriSuffix(),
        ]);

        $this->assertCount(1, $foundNodes, 'The fallback URL must resolve to exactly one (origin-addressed) row');
        $originContextPath = NodePaths::generateContextPath(
            '/sites/example/fallback-page',
            'live',
            ['language' => $this->getRoutingLanguageDimensionValuesForPreset('en')]
        );
        $this->assertArrayHasKey($originContextPath, $foundNodes, 'The row must be addressed in the ORIGIN dimension');
    }

    /**
     * @test
     */
    public function importantPagesKeepsRealVariantsInTheirOwnDimension(): void
    {
        $foundNodes = $this->findImportantPagesForCandidates([
            'https://example.com/uk/variant-page' . $this->getDefaultUriSuffix(),
        ]);

        $this->assertCount(1, $foundNodes);
        $variantContextPath = NodePaths::generateContextPath(
            '/sites/example/variant-page',
            'live',
            ['language' => ['en_UK', 'en']]
        );
        $this->assertArrayHasKey($variantContextPath, $foundNodes, 'A real variant must stay addressed in its own dimension');
    }

    /**
     * @test
     */
    public function updateRejectsWritesToFallbackContextPaths(): void
    {
        // explicit chain order: the first value is the context's target dimension
        $fallbackContextPath = NodePaths::generateContextPath(
            '/sites/example/fallback-page',
            'live',
            ['language' => ['en_UK', 'en']]
        );

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1752060000001);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => $fallbackContextPath,
                'properties' => ['focusKeyword' => 'must-not-be-written'],
                'images' => [],
            ]),
        ]);
    }

    /**
     * @test
     */
    public function updateAcceptsWritesToRealVariants(): void
    {
        // explicit chain order: the first value is the context's target dimension
        $variantContextPath = NodePaths::generateContextPath(
            '/sites/example/variant-page',
            'live',
            ['language' => ['en_UK', 'en']]
        );

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => $variantContextPath,
                'properties' => ['focusKeyword' => 'variant-keyword'],
                'images' => [],
            ]),
        ]);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $ukContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en_UK', 'en']],
            'targetDimensions' => ['language' => 'en_UK'],
        ]);
        $this->assertSame('variant-keyword', $ukContext->getNode('/sites/example/variant-page')->getProperty('focusKeyword'));

        // ...and the origin (en) stays untouched
        $enContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en']],
            'targetDimensions' => ['language' => 'en'],
        ]);
        $this->assertNull($enContext->getNode('/sites/example/fallback-page')->getProperty('focusKeyword'));
    }

    private function createUkContext(): Context
    {
        return $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en_UK', 'en']],
            'targetDimensions' => ['language' => 'en_UK'],
        ]);
    }

    /**
     * Varies ONLY the image node of the fallback page into en_UK, leaving the document itself a
     * fallback - the shape an editor produces by localizing a single content element on a
     * shine-through page.
     */
    private function createUkVariantOfTheFallbackPageImage(): void
    {
        $englishContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en']],
            'targetDimensions' => ['language' => 'en'],
        ]);
        $englishContext->getNode(self::FALLBACK_PAGE_IMAGE_PATH)->createVariantForContext($this->createUkContext());

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * The image module writes image nodes only - it sends no document properties at all. Blocking
     * such an update because the DOCUMENT is a fallback refuses work that materializes nothing:
     * the image node is already a real variant.
     *
     * @test
     */
    public function updateAcceptsImageOnlyWritesOnFallbackDocuments(): void
    {
        $this->createUkVariantOfTheFallbackPageImage();

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => NodePaths::generateContextPath(self::FALLBACK_PAGE_PATH, 'live', ['language' => ['en_UK', 'en']]),
                'properties' => [],
                'images' => [
                    NodePaths::generateContextPath(self::FALLBACK_PAGE_IMAGE_PATH, 'live', ['language' => ['en_UK']]) => [
                        'alternativeText' => 'Ein britischer Alternativtext',
                    ],
                ],
            ]),
        ]);

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();

        $ukContext = $this->createUkContext();
        $this->assertSame(
            'Ein britischer Alternativtext',
            $ukContext->getNode(self::FALLBACK_PAGE_IMAGE_PATH)->getProperty('alternativeText')
        );
        // The decisive assertion: the document must NOT have been materialized as a side effect.
        $this->assertSame(
            ['en'],
            $ukContext->getNode(self::FALLBACK_PAGE_PATH)->getNodeData()->getDimensionValues()['language'],
            'Writing an image must not materialize a variant of its fallback document'
        );
    }

    /**
     * The guard moves to the image node, it does not disappear: an image that is itself only a
     * fallback would be materialized by the write, which stays an explicit editor decision.
     *
     * The document is addressed through the preset's FULL chain, as the image module does for a
     * shine-through container row. A context restricted to ["en_UK"] alone would not see the
     * en nodes at all, which is a different case - see the test below.
     *
     * @test
     */
    public function updateStillRejectsWritesToFallbackImageNodes(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1752060000001);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => NodePaths::generateContextPath(self::FALLBACK_PAGE_PATH, 'live', ['language' => ['en_UK', 'en']]),
                'properties' => [],
                'images' => [
                    NodePaths::generateContextPath(self::FALLBACK_PAGE_IMAGE_PATH, 'live', ['language' => ['en_UK']]) => [
                        'alternativeText' => 'must-not-be-written',
                    ],
                ],
            ]),
        ]);
    }

    /**
     * A context path that resolves to no node at all - a stale one from a reloaded module, or a
     * dimension the node does not exist in - used to be handed to the fallback guard, which
     * tolerates NULL, and was then dereferenced.
     *
     * @test
     */
    public function updateRejectsContextPathsThatResolveToNoNode(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1757682000001);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => NodePaths::generateContextPath('/sites/example/no-such-page', 'live', ['language' => ['en']]),
                'properties' => ['focusKeyword' => 'must-not-be-written'],
                'images' => [],
            ]),
        ]);
    }
}

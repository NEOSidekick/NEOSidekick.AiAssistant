<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use Psr\Log\LoggerInterface;

/**
 * On an installation WITH a configured language dimension, a node without any value for that
 * dimension is a broken state: Neos requires "./flow node:migrate 20150716212459" after adding
 * or removing a dimension. Such rows cannot be assigned to a language, so the batch modules
 * exclude them — with or without an active language filter — instead of generating content in
 * a guessed language.
 */
class NodeServiceWithoutLanguageDimensionValuesTest extends FunctionalTestCase
{
    private const DIMENSION_LESS_NODE_PATHS = ['/sites/example/legacy-page', '/sites/example/another-legacy-page'];

    protected array $dimensions = ['de', 'en'];
    protected array $siteHosts = ['example.com'];

    public function setUp(): void
    {
        parent::setUp();
        $exampleSiteNode = $this->rootNode->getNode('/sites/example');
        $page1 = $this->createPageWithImageNodes($exampleSiteNode, 'node-wan-kenodi', 'Seite 1', ['image1.jpg']);
        $page2 = $this->createPageWithImageNodes($exampleSiteNode, 'node-mc-nodeface', 'Seite 2', ['image1.jpg']);

        $englishContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en']]
        ]);
        $exampleSiteNode->createVariantForContext($englishContext);
        $page1->createVariantForContext($englishContext);
        $page2->createVariantForContext($englishContext);

        foreach (self::DIMENSION_LESS_NODE_PATHS as $dimensionLessNodePath) {
            $this->createDimensionLessDocumentNodeData($page2->getNodeData(), $dimensionLessNodePath);
        }

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    public function tearDown(): void
    {
        // Defensive: the NodeService is prototype-scoped by default, but a distribution may
        // configure it as a singleton - a logger mock injected by a test must never leak.
        $this->inject($this->objectManager->get(NodeService::class), 'systemLogger', $this->objectManager->get(LoggerInterface::class));

        parent::tearDown();
    }

    /**
     * Creates a document node row WITHOUT values for the language dimension, as it exists on
     * installations where content predates the dimension configuration. Context-based creation
     * always stamps the context's dimensions onto the NodeData, so the row has to be built
     * directly — mirroring {@see \Neos\ContentRepository\Domain\Model\Node::createVariantForContext},
     * but with no dimensions at all.
     */
    private function createDimensionLessDocumentNodeData(NodeData $templateNodeData, string $nodePath): void
    {
        $nodeName = NodePaths::getNodeNameFromPath($nodePath);
        $dimensionLessNodeData = new NodeData($nodePath, $this->liveWorkspace, null, null);
        $dimensionLessNodeData->similarize($templateNodeData);
        $dimensionLessNodeData->setProperty('uriPathSegment', $nodeName);
        $dimensionLessNodeData->setProperty('title', $nodeName);
        // similarize() writes properties, which already registers the new NodeData via
        // NodeData::addOrUpdate() - adding it again would throw a KnownObjectException.
        if ($this->persistenceManager->isNewObject($dimensionLessNodeData)) {
            $this->nodeDataRepository->add($dimensionLessNodeData);
        }
        $this->persistenceManager->persistAll();
    }

    /**
     * @param array<string, mixed> $foundNodes keyed by context path
     *
     * @return array<string>
     */
    private function getNodePathsOf(array $foundNodes): array
    {
        return array_map(
            static fn(string $contextPath): string => NodePaths::explodeContextPath($contextPath)['nodePath'],
            array_keys($foundNodes)
        );
    }

    private function getGermanContextPath(string $nodePath): string
    {
        return NodePaths::generateContextPath($nodePath, 'live', ['language' => $this->getStoredLanguageDimensionValuesForPreset('de')]);
    }

    /**
     * @test
     */
    public function itDoesNotFindNodesWithoutLanguageDimensionValuesWhenFilteringForALanguage(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'live', languageDimensionFilter: 'de');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertEmpty(array_intersect(self::DIMENSION_LESS_NODE_PATHS, $this->getNodePathsOf($foundNodes)));
        $this->assertArrayHasKey($this->getGermanContextPath('/sites/example'), $foundNodes);
        $this->assertArrayHasKey($this->getGermanContextPath('/sites/example/node-wan-kenodi'), $foundNodes);
        $this->assertArrayHasKey($this->getGermanContextPath('/sites/example/node-mc-nodeface'), $foundNodes);
        $this->assertCount(3, $foundNodes, 'Only the three German variants may be returned when filtering for "de"');
    }

    /**
     * The regression this test guards: with an EMPTY language filter the exclusion used to be
     * skipped, so the dimension-less row passed through and was generated for in a guessed
     * language. Exclusion must not depend on whether a filter happens to be set.
     *
     * @test
     */
    public function itDoesNotFindNodesWithoutLanguageDimensionValuesWithoutALanguageFilter(): void
    {
        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $findDocumentNodesFilter = new FindDocumentNodesFilter(filter: 'custom', workspace: 'live');
        $foundNodes = $nodeService->find($findDocumentNodesFilter, $controllerContext);

        $this->assertEmpty(array_intersect(self::DIMENSION_LESS_NODE_PATHS, $this->getNodePathsOf($foundNodes)));
        $this->assertArrayHasKey($this->getGermanContextPath('/sites/example'), $foundNodes);
        $this->assertArrayHasKey(NodePaths::generateContextPath('/sites/example', 'live', ['language' => ['en']]), $foundNodes);
        $this->assertCount(6, $foundNodes, 'Three German plus three English variants must be returned, and nothing else');
    }

    /**
     * Two rows are skipped, so a per-node implementation would log twice. Asserting the
     * aggregated count in the single message is what pins "one warning per request" down.
     *
     * @test
     */
    public function itLogsOneAggregatedWarningPerRequestForSkippedNodes(): void
    {
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('skipped 2 node variant(s)'),
                $this->stringContains('./flow node:migrate 20150716212459')
            ));

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'systemLogger', $loggerMock);

        $nodeService->find(
            new FindDocumentNodesFilter(filter: 'custom', workspace: 'live'),
            $this->createControllerContextForDomain('example.com')
        );
    }
}

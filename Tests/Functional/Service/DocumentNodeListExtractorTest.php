<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The document list is built from ONE findSubtree() query over the document tree (Neos 8
 * parity): documents are discovered through document chains, `depth` counts document levels,
 * and disabled documents stay in the list with isHidden=true (the node's own state).
 */
class DocumentNodeListExtractorTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $siteNode = $this->getNodeByPath('/sites/example');
        $pageA = $this->createPageWithImageNodes($siteNode, 'page-a', 'Page A', []);
        $this->createPageWithImageNodes($pageA, 'page-a1', 'Page A1', []);
        $pageB = $this->createPageWithImageNodes($siteNode, 'page-b', 'Page B', []);
        $this->createPageWithImageNodes($pageB, 'page-b1', 'Page B1', []);
        $this->disableNode($pageB, 'live');
    }

    /**
     * @return array<string, array> documents indexed by their old-style path suffix
     */
    private function extractDocumentsByName(int $depth = -1): array
    {
        /** @var DocumentNodeListExtractor $extractor */
        $extractor = $this->objectManager->get(DocumentNodeListExtractor::class);
        $result = $extractor->extract(
            workspace: 'live',
            dimensions: $this->dimensionsForPrimaryLanguage(),
            siteNodeName: 'example',
            depth: $depth
        );
        $byName = [];
        foreach ($result['documents'] as $document) {
            $byName[$document['title']] = $document;
        }

        return $byName;
    }

    private function dimensionsForPrimaryLanguage(): array
    {
        $dimensionId = $this->languageDimensionId();

        return $dimensionId === null ? [] : [$dimensionId->value => [$this->primaryLanguage()]];
    }

    /**
     * @test
     */
    public function depthCountsDocumentLevelsAndPathsUseTheAbsoluteFormat(): void
    {
        $documents = $this->extractDocumentsByName();

        $this->assertCount(5, $documents, 'Site + four pages must be listed');
        $this->assertSame(0, $documents['example']['depth']);
        $this->assertSame(1, $documents['Page A']['depth']);
        $this->assertSame(2, $documents['Page A1']['depth']);
        $this->assertSame('/<Neos.Neos:Sites>/example/page-a/page-a1', $documents['Page A1']['path']);
        $this->assertSame(1, $documents['Page A']['childDocumentCount']);
        $this->assertSame(2, $documents['example']['childDocumentCount']);
    }

    /**
     * @test
     */
    public function boundedDepthStillReportsChildDocumentCountsAtTheBoundary(): void
    {
        $documents = $this->extractDocumentsByName(1);

        $this->assertCount(3, $documents, 'Site and the two level-1 pages only');
        $this->assertArrayNotHasKey('Page A1', $documents);
        // The boundary nodes' counts need the level below the bound
        $this->assertSame(1, $documents['Page A']['childDocumentCount']);
        $this->assertSame(1, $documents['Page B']['childDocumentCount']);
    }

    /**
     * @test
     */
    public function maximumIntegerDepthIsEffectivelyUnlimited(): void
    {
        $documents = $this->extractDocumentsByName(PHP_INT_MAX);

        $this->assertArrayHasKey('Page A1', $documents);
        $this->assertSame(2, $documents['Page A1']['depth']);
    }

    /**
     * @test
     */
    public function disabledDocumentsAreListedWithTheirOwnHiddenState(): void
    {
        $documents = $this->extractDocumentsByName();

        $this->assertTrue($documents['Page B']['isHidden'], 'A disabled document must be listed with isHidden=true');
        $this->assertFalse($documents['Page B1']['isHidden'], 'isHidden reflects the node\'s OWN state, not an inherited one');
        $this->assertFalse($documents['Page A']['isHidden']);
    }
}

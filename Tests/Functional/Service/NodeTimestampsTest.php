<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use NEOSidekick\AiAssistant\Service\SearchNodesExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The document list and the node search pass on the three timestamps Neos stores for a node,
 * and the document list can be sorted by one of them.
 *
 * Three pages below the site, in tree order: `first` (created 2024, published 2025), `second`
 * (created 2026, never published) and `third` (created 2025, published 2026). The timestamps are
 * written to the NodeData records directly, so the test does not depend on when it runs.
 */
class NodeTimestampsTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    public function setUp(): void
    {
        parent::setUp();

        $siteNode = $this->rootNode->getNode('/sites/example');
        $this->createPageWithTimestamps($siteNode, 'first', '2024-03-01 10:00:00', '2024-03-02 11:00:00', '2025-01-15 09:00:00');
        $this->createPageWithTimestamps($siteNode, 'second', '2026-05-01 10:00:00', '2026-05-01 10:00:00', null);
        $this->createPageWithTimestamps($siteNode, 'third', '2025-08-01 10:00:00', '2026-09-01 12:30:00', '2026-09-01 12:31:00');

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function everyDocumentCarriesTheTimestampsNeosStoresForIt(): void
    {
        $documents = $this->documentsByPath($this->extract());

        self::assertSame(
            [
                'creationDateTime' => '2024-03-01T10:00:00+01:00',
                'lastModificationDateTime' => '2024-03-02T11:00:00+01:00',
                'lastPublicationDateTime' => '2025-01-15T09:00:00+01:00',
            ],
            $this->timestampsOf($documents['/sites/example/first'])
        );
        self::assertSame(
            '2026-09-01T12:30:00+02:00',
            $documents['/sites/example/third']['lastModificationDateTime'],
            'the offset is the one in effect at that moment, here summer time'
        );
    }

    /**
     * @test
     */
    public function aDocumentThatWasNeverPublishedHasNoPublicationTimestamp(): void
    {
        $document = $this->documentsByPath($this->extract())['/sites/example/second'];

        self::assertArrayHasKey('lastPublicationDateTime', $document);
        self::assertNull($document['lastPublicationDateTime']);
        self::assertSame('2026-05-01T10:00:00+02:00', $document['creationDateTime']);
    }

    /**
     * @test
     */
    public function withoutSortByTheDocumentsKeepTreeOrder(): void
    {
        $result = $this->extract();

        self::assertSame(['example', 'first', 'second', 'third'], $this->pageNames($result));
        self::assertArrayNotHasKey('sortBy', $result);
        self::assertArrayNotHasKey('sortDirection', $result);
    }

    /**
     * @test
     */
    public function sortingByCreationPutsTheNewestDocumentFirstByDefault(): void
    {
        $result = $this->extract(sortBy: 'creationDateTime');

        self::assertSame(['second', 'third', 'first'], $this->pageNamesWithoutSite($result));
        self::assertSame('creationDateTime', $result['sortBy'], 'the response names the applied sorting');
        self::assertSame('desc', $result['sortDirection']);
    }

    /**
     * @test
     */
    public function sortingAscendingPutsTheOldestDocumentFirst(): void
    {
        $result = $this->extract(sortBy: 'creationDateTime', sortDirection: 'asc');

        self::assertSame(['first', 'third', 'second'], $this->pageNamesWithoutSite($result));
        self::assertSame('asc', $result['sortDirection']);
    }

    /**
     * @test
     */
    public function sortingByModificationUsesTheModificationTimestamp(): void
    {
        self::assertSame(
            ['third', 'second', 'first'],
            $this->pageNamesWithoutSite($this->extract(sortBy: 'lastModificationDateTime'))
        );
    }

    /**
     * @test
     */
    public function neverPublishedDocumentsComeLastInBothDirections(): void
    {
        $descending = $this->pageNames($this->extract(sortBy: 'lastPublicationDateTime'));
        $ascending = $this->pageNames($this->extract(sortBy: 'lastPublicationDateTime', sortDirection: 'asc'));

        self::assertSame(['third', 'first'], array_slice($descending, 0, 2));
        self::assertSame(['first', 'third'], array_slice($ascending, 0, 2));
        self::assertSame(
            ['example', 'second'],
            array_slice($descending, 2),
            'the site node and "second" were never published; among themselves they keep tree order'
        );
        self::assertSame(['example', 'second'], array_slice($ascending, 2));
    }

    /**
     * @test
     */
    public function anUnknownSortFieldIsRefusedNamingTheAllowedOnes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot sort by "title". Allowed values for sortBy: creationDateTime, lastModificationDateTime, lastPublicationDateTime'
        );

        $this->extract(sortBy: 'title');
    }

    /**
     * @test
     */
    public function anUnknownSortDirectionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sortDirection "newest". Allowed values: asc, desc');

        $this->extract(sortBy: 'creationDateTime', sortDirection: 'newest');
    }

    /**
     * @test
     */
    public function aSearchHitCarriesTheTimestampsOfTheNodeThatWasFound(): void
    {
        /** @var SearchNodesExtractor $searchNodesExtractor */
        $searchNodesExtractor = $this->objectManager->get(SearchNodesExtractor::class);

        $result = $searchNodesExtractor->search(query: 'Page third', workspace: 'live');

        self::assertSame(1, $result['resultCount']);
        self::assertSame(
            [
                'creationDateTime' => '2025-08-01T10:00:00+02:00',
                'lastModificationDateTime' => '2026-09-01T12:30:00+02:00',
                'lastPublicationDateTime' => '2026-09-01T12:31:00+02:00',
            ],
            $this->timestampsOf($result['results'][0])
        );
    }

    private function createPageWithTimestamps(
        NodeInterface $parentNode,
        string $nodeName,
        string $created,
        string $modified,
        ?string $published
    ): void {
        $page = $parentNode->createNodeFromTemplate($this->createDocumentNodeTemplate('Page ' . $nodeName), $nodeName);
        $page->setProperty('uriPathSegment', $nodeName);
        // Flush first: the modification timestamp is rewritten whenever the record's content changes.
        $this->persistenceManager->persistAll();

        $nodeData = $page->getNodeData();
        ObjectAccess::setProperty($nodeData, 'creationDateTime', new \DateTime($created), true);
        ObjectAccess::setProperty($nodeData, 'lastModificationDateTime', new \DateTime($modified), true);
        $nodeData->setLastPublicationDateTime($published !== null ? new \DateTime($published) : null);
        $this->nodeDataRepository->update($nodeData);
        $this->persistenceManager->persistAll();
    }

    private function extract(?string $sortBy = null, string $sortDirection = 'desc'): array
    {
        /** @var DocumentNodeListExtractor $extractor */
        $extractor = $this->objectManager->get(DocumentNodeListExtractor::class);

        return $extractor->extract(
            workspace: 'live',
            siteNodeName: 'example',
            sortBy: $sortBy,
            sortDirection: $sortDirection
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function documentsByPath(array $result): array
    {
        return array_column($result['documents'], null, 'path');
    }

    /**
     * @return array<int, string>
     */
    private function pageNames(array $result): array
    {
        return array_map(
            static fn(array $document): string => basename($document['path']),
            $result['documents']
        );
    }

    /**
     * The site node is created by the test setup at the time the test runs, so its position in a
     * list sorted by creation or modification is not fixed.
     *
     * @return array<int, string>
     */
    private function pageNamesWithoutSite(array $result): array
    {
        return array_values(array_diff($this->pageNames($result), ['example']));
    }

    /**
     * @return array<string, string|null>
     */
    private function timestampsOf(array $node): array
    {
        return array_intersect_key($node, array_flip([
            'creationDateTime',
            'lastModificationDateTime',
            'lastPublicationDateTime',
        ]));
    }
}

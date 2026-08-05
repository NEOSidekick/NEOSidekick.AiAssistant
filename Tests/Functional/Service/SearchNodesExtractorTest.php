<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Service\SearchNodesExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The identifier lookup must evaluate the nodeTypeFilter string with the same semantics as
 * the search path's findDescendantNodes() (NodeTypeCriteria: multi-type lists, deny wins) —
 * a plain isOfType() call misreads filters like "A,B" or "!C".
 */
class SearchNodesExtractorTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    private string $searchPageAggregateId;
    private string $siblingPageAggregateId;

    protected function setUpContentInLive(): void
    {
        $siteNode = $this->getNodeByPath('/sites/example');
        $page = $this->createPageWithImageNodes($siteNode, 'search-page', 'Search Page', []);
        $this->searchPageAggregateId = $page->aggregateId->value;
        // Lexical-prefix sibling of search-page — must NOT match a search-page path restriction
        $sibling = $this->createPageWithImageNodes($siteNode, 'search-page-other', 'Search Page Other', []);
        $this->siblingPageAggregateId = $sibling->aggregateId->value;
    }

    private function search(string $nodeTypeFilter, ?string $query = null, ?string $pathStartingPoint = null): array
    {
        /** @var SearchNodesExtractor $extractor */
        $extractor = $this->objectManager->get(SearchNodesExtractor::class);
        $dimensionId = $this->languageDimensionId();

        return $extractor->search(
            query: $query ?? $this->searchPageAggregateId,
            workspace: 'live',
            dimensions: $dimensionId === null ? [] : [$dimensionId->value => [$this->primaryLanguage()]],
            nodeTypeFilter: $nodeTypeFilter,
            pathStartingPoint: $pathStartingPoint
        );
    }

    /**
     * @test
     */
    public function identifierLookupAcceptsMultiTypeFilters(): void
    {
        $result = $this->search('Neos.Neos:Document,Neos.Neos:Content');

        $this->assertSame(1, $result['resultCount'], 'A multi-type allow-list containing Document must match the page');
        $this->assertSame($this->searchPageAggregateId, $result['results'][0]['identifier']);
    }

    /**
     * @test
     */
    public function identifierLookupLetsDenyRulesWin(): void
    {
        $result = $this->search('Neos.Neos:Node,!Neos.Neos:Document');

        $this->assertSame(0, $result['resultCount'], 'A deny rule must exclude the page even when an allow rule matches');
    }

    /**
     * @test
     */
    public function identifierLookupAllowsEverythingElseWithDenyOnlyFilters(): void
    {
        $result = $this->search('!Neos.Neos:ContentCollection');

        $this->assertSame(1, $result['resultCount'], 'A deny-only filter must allow all non-denied types');
    }

    /**
     * @test
     */
    public function pathRestrictionDoesNotCrossSegmentBoundaries(): void
    {
        $result = $this->search(
            'Neos.Neos:Document',
            query: $this->siblingPageAggregateId,
            pathStartingPoint: '/sites/example/search-page'
        );

        $this->assertSame(0, $result['resultCount'], 'A "search-page" restriction must not match the sibling "search-page-other"');
    }
}

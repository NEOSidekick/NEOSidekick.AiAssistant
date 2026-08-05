<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Service\NodeTreeExtractor;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * The node-tree agent API defaults to no dimensions; on a dimensioned content repository
 * an empty dimension space point matches nothing, so the extractor must fall back to the
 * most general dimension space point (like the other extractors do).
 */
class NodeTreeExtractorTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    private string $treePageAggregateId;

    protected function setUpContentInLive(): void
    {
        $siteNode = $this->getNodeByPath('/sites/example');

        // The page must live in the MOST GENERAL dimension space point — that is what an
        // empty dimensions array must resolve to.
        $generalLanguage = $this->mostGeneralLanguage();
        if ($generalLanguage !== null && $generalLanguage !== $this->primaryLanguage()) {
            $this->createLanguageVariant($siteNode, $generalLanguage);
            $siteNode = $this->getNodeByPath('/sites/example', 'live', $generalLanguage);
        }

        $page = $this->createPageWithImageNodes($siteNode, 'tree-page', 'Tree Page', [], $generalLanguage);
        $this->treePageAggregateId = $page->aggregateId->value;
    }

    private function mostGeneralLanguage(): ?string
    {
        $dimensionId = $this->languageDimensionId();
        if ($dimensionId === null) {
            return null;
        }
        $rootGeneralizations = $this->contentRepository->getVariationGraph()->getRootGeneralizations();
        $mostGeneral = reset($rootGeneralizations);

        return $mostGeneral === false ? null : $mostGeneral->getCoordinate($dimensionId);
    }

    /**
     * @test
     */
    public function emptyDimensionsFallBackToTheMostGeneralDimensionSpacePoint(): void
    {
        /** @var NodeTreeExtractor $extractor */
        $extractor = $this->objectManager->get(NodeTreeExtractor::class);

        $result = $extractor->extract($this->treePageAggregateId, 'live', []);

        $this->assertSame($this->treePageAggregateId, $result['rootNode']['id']);
        $this->assertArrayHasKey('main', $result['rootNode']['children'], 'The tethered main collection must be extracted');
    }
}

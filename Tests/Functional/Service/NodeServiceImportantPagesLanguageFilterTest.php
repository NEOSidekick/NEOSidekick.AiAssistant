<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * An empty language dimension filter means "all languages": findImportantPages() must not
 * silently return an empty result when no filter is passed (unlike find(), it applies the
 * language matching unconditionally, so the "empty filter matches everything" rule must
 * live inside the matcher itself).
 */
class NodeServiceImportantPagesLanguageFilterTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $siteNode = $this->getNodeByPath('/sites/example');
        $this->createPageWithImageNodes($siteNode, 'lang-page', 'Lang Page', []);
    }

    /**
     * @test
     */
    public function importantPagesWithoutLanguageFilterReturnsRows(): void
    {
        $languageSegment = $this->primaryLanguage() !== null ? $this->languageUriSegment() . '/' : '';
        $pageUrl = 'https://example.com/' . $languageSegment . 'lang-page' . $this->getUriPathSuffix();

        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)->disableOriginalConstructor()->getMock();
        $apiFacadeMock->method('getMostRelevantInternalSeoUrisByHosts')->willReturn([$pageUrl]);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $filter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live'
            // deliberately no languageDimensionFilter
        );
        $foundNodes = $nodeService->findImportantPages($filter, $this->createControllerContextForDomain('example.com'), 'de');

        $this->assertCount(1, $foundNodes, 'An empty language filter must match all languages, not none');
        $this->assertArrayHasKey($this->addressForPath('/sites/example/lang-page'), $foundNodes);
    }
}

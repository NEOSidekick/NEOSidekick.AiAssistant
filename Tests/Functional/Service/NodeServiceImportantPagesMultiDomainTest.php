<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

class NodeServiceImportantPagesMultiDomainTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com', 'example2.com'];

    protected function setUpContentInLive(): void
    {
        $site1 = $this->getNodeByPath('/sites/example');
        $this->createPageWithImageNodes($site1, 'site1-page', 'Site1', ['image1.jpg']);

        $site2 = $this->getNodeByPath('/sites/example2');
        $this->createPageWithImageNodes($site2, 'site2-page', 'Site2', ['image1.jpg']);
    }

    /**
     * Important pages should be filtered to the current ControllerContext domain.
     * @test
     */
    public function itFiltersImportantPagesByCurrentDomain(): void
    {
        if ($this->primaryLanguage() === null) {
            $this->markTestSkipped('This test needs a language dimension: the mocked host expectation assumes language-segmented entry URLs.');
        }

        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Return candidates from both hosts
        $candidates = [
            'https://example.com/' . $this->languageUriSegment() . '/site1-page' . $this->getUriPathSuffix(),
            'https://example2.com/' . $this->languageUriSegment() . '/site2-page' . $this->getUriPathSuffix(),
        ];
        $apiFacadeMock
            ->expects($this->once())
            ->method('getMostRelevantInternalSeoUrisByHosts')
            ->with(
                $this->equalTo(['https://example.com/' . $this->languageUriSegment()]),
                $this->equalTo('de')
            )
            ->willReturn($candidates);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $filter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            focusKeywordPropertyFilter: 'only-empty-focus-keywords',
            languageDimensionFilter: $this->primaryLanguage()
        );

        // Ask for example.com context; expect only that site's page is returned
        $controllerContext = $this->createControllerContextForDomain('example.com');
        $foundNodes = $nodeService->findImportantPages($filter, $controllerContext, 'de');

        $this->assertIsArray($foundNodes);
        $this->assertCount(1, $foundNodes, 'Expected only current-domain nodes to be returned');
        $this->assertArrayHasKey(
            $this->addressForPath('/sites/example/site1-page', 'live'),
            $foundNodes,
            'Expected node from the /sites/example tree only'
        );
    }
}

<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use HttpRequest;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Flow\Tests\Unit\Mvc\ActionRequestTest;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteImportService;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeFindingService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Service\SiteService;
use Psr\Http\Message\ServerRequestInterface;

class AssetServiceTest extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    public function setUp(): void
    {
        parent::setUp();

        // Import neos demo site
        $siteImportService = $this->objectManager->get(SiteImportService::class);
        $siteImportService->importFromPackage('Neos.Demo');

        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $domain = new Domain();
        $domain->setScheme('http');
        $domain->setHostname('example.com');
        $site = $siteRepository->findOneByNodeName('neosdemo');
        $site->setPrimaryDomain($domain);
        $siteRepository->update($site);
    }

    /** @test */
    public function it_()
    {
        $findDocumentNodesFilter = new FindDocumentNodesFilter(
            'important',
            'live'
        );
        $mockHttpRequest = new ServerRequest('GET', 'http://example.com');
        $actionRequest = ActionRequest::fromHttpRequest($mockHttpRequest);
        $actionResponse = new ActionResponse();
        $controllerContext = new ControllerContext($actionRequest, $actionResponse, new Arguments(), new UriBuilder());
        $mockApiFacade = $this->getMockBuilder(ApiFacade::class)->disableOriginalConstructor()->getMock();
        $mockApiFacade->method('getMostRelevantInternalSeoUrisByHosts')->willReturn([
            'http://example.com/de',
            'http://example.com/en',
        ]);

        $mockNodeFindingService = $this->getMockBuilder(NodeFindingService::class)->disableOriginalConstructor()->getMock();
        $mockNodeFindingService->expects($this->exactly(2))->method('tryToResolvePublicUriToNode')->willReturn(null);

        $nodeService = new NodeService();
        $this->inject($nodeService, 'apiFacade', $mockApiFacade);
        $nodeService->findImportantPages($findDocumentNodesFilter, $controllerContext, 'de');
        $this->assertTrue(true);
    }
}

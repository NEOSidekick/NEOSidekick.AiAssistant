<?php

namespace NEOSidekick\AiAssistant\Tests\Functional;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\ContentRepository\Domain\Repository\ContentDimensionRepository;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Image;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\SiteService;

abstract class FunctionalTestCase extends \Neos\Flow\Tests\FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;
    protected array $dimensions = [];
    protected array $siteHosts = [];

    protected ContentDimensionRepository $contentDimensionRepository;
    protected ContextFactory $contextFactory;
    protected ?NodeDataRepository $nodeDataRepository = null;
    protected WorkspaceRepository $workspaceRepository;
    protected ?Node $rootNode = null;
    protected ?Node $sitesNode = null;
    protected ?Workspace $liveWorkspace = null;
    protected ?Workspace $groupWorkspace = null;
    protected ?NodeTypeManager $nodeTypeManager = null;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        $this->currentUserWorkspace = explode('.', uniqid('user-', true))[0];
        $this->currentGroupWorkspace = explode('.', uniqid('group-', true))[0];
        $this->configureNodeDimensions();
        $this->setUpRootNodeAndRepository();

        // Purge existing sites/domains and content under /sites to ensure tests are isolated
        $this->purgeSitesDomainsAndContent();

        foreach($this->siteHosts as $i => $siteHost) {
            $this->createSite(explode('.', $siteHost)[0], $siteHost);
        }

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        $this->saveNodesAndTearDownRootNodeAndRepository();
        parent::tearDown();
    }

    private function configureNodeDimensions(): void
    {
        /** @var ContentDimensionRepository $configuration */
        $this->contentDimensionRepository = $this->objectManager->get(ContentDimensionRepository::class);

        $contentDimensionsConfiguration = [];
        if ($this->dimensions) {
            $contentDimensionsConfiguration = [
                'language' => [
                    'default' => $this->dimensions[0],
                    'defaultPreset' => $this->dimensions[0],
                    'presets' => []
                ]
            ];
            foreach ($this->dimensions as $dimension) {
                $contentDimensionsConfiguration['language']['presets'][$dimension] = [
                    'values' => [$dimension]
                ];
            }
        }

        $this->contentDimensionRepository->setDimensionsConfiguration($contentDimensionsConfiguration);
    }

    protected function setUpRootNodeAndRepository(): void
    {
        $this->contextFactory = $this->objectManager->get(ContextFactory::class);

        $this->workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        if ($this->liveWorkspace === null) {
            $this->liveWorkspace = new Workspace('live');
            $this->workspaceRepository->add($this->liveWorkspace);
            $this->groupWorkspace = new Workspace($this->currentGroupWorkspace, $this->liveWorkspace);
            $this->workspaceRepository->add($this->groupWorkspace);
            $this->workspaceRepository->add(new Workspace($this->currentUserWorkspace, $this->groupWorkspace));
            $this->persistenceManager->persistAll();
        }

        $liveContext = $this->contextFactory->create(['workspaceName' => 'live']);
        $personalContext = $this->contextFactory->create(['workspaceName' => $this->currentUserWorkspace]);

        // Make sure the Workspace was created.
        $this->liveWorkspace = $personalContext->getWorkspace()->getBaseWorkspace()->getBaseWorkspace();
        $this->nodeDataRepository = $this->objectManager->get(NodeDataRepository::class);
        $this->rootNode = $liveContext->getNode('/');
        $this->sitesNode = $liveContext->getNode('/sites');
        if ($this->sitesNode === null) {
            $this->sitesNode = $this->rootNode->createNode(NodePaths::getNodeNameFromPath(SiteService::SITES_ROOT_PATH));
        }

        $this->persistenceManager->persistAll();
    }

    protected function saveNodesAndTearDownRootNodeAndRepository()
    {
        if ($this->nodeDataRepository !== null) {
            $this->nodeDataRepository->flushNodeRegistry();
        }
        /** @var NodeFactory $nodeFactory */
        $nodeFactory = $this->objectManager->get(NodeFactory::class);
        $nodeFactory->reset();
        $this->contextFactory->reset();

        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
        $this->nodeDataRepository = null;
        $this->rootNode = null;
        $this->sitesNode = null;
    }

    /**
     * Remove all sites/domains and content under /sites to avoid cross-test interference.
     */
    protected function purgeSitesDomainsAndContent(): void
    {
        // Remove all domains and sites from repositories
        /** @var SiteRepository $siteRepository */
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        /** @var DomainRepository $domainRepository */
        $domainRepository = $this->objectManager->get(DomainRepository::class);

        foreach ($domainRepository->findAll() as $domain) {
            $domainRepository->remove($domain);
        }
        foreach ($siteRepository->findAll() as $site) {
            $siteRepository->remove($site);
        }
        $this->persistenceManager->persistAll();

        // Remove all nodes under /sites in the live context
        $liveContext = $this->contextFactory->create(['workspaceName' => 'live']);
        $sitesNode = $liveContext->getNode('/sites');
        if ($sitesNode !== null) {
            foreach ($sitesNode->getChildNodes() as $child) {
                $child->remove();
            }
        }
        $this->persistenceManager->persistAll();
    }

    /**
     * @param Node $parentNode
     * @param string        $title
     * @param array         $imageFixtureFilenames
     *
     * @return NodeInterface
     * @throws \Neos\ContentRepository\Exception\NodeConfigurationException
     * @throws \Neos\ContentRepository\Exception\NodeException
     */
    protected function createPageWithImageNodes(NodeInterface $parentNode, string $nodeName, string $title, array $imageFixtureFilenames): NodeInterface
    {
        /** @var Node $documentNode */
        $documentNode = $parentNode->createNodeFromTemplate($this->createDocumentNodeTemplate($title), $nodeName);
        $documentNode->setProperty('uriPathSegment', $nodeName);
        $mainContentCollection = $documentNode->findNamedChildNode(NodeName::fromString('main'));
        foreach ($imageFixtureFilenames as $imageFixtureFilename) {
            $mainContentCollection->createNodeFromTemplate($this->createImageNodeTemplate($imageFixtureFilename), 'image-' . explode('.', $imageFixtureFilename)[0]);
        }
        return $documentNode;
    }

    protected function createDocumentNodeTemplate(string $title): NodeTemplate
    {
        $nodeTemplate = new NodeTemplate();
        $nodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:Page'));
        $nodeTemplate->setProperty('title', $title);
        return $nodeTemplate;
    }

    protected function createImageNodeTemplate(string $imageFixtureFilename): NodeTemplate
    {
        $nodeTemplate = new NodeTemplate();
        $nodeTemplate->setNodeType($this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:Image'));
        $nodeTemplate->setProperty('image', $this->importImage($imageFixtureFilename));
        return $nodeTemplate;
    }

    private function importImage(string $fixtureFilename): Image
    {
        $resource = $this->objectManager->get(ResourceManager::class)->importResource(__DIR__ . '/../Fixtures/' . $fixtureFilename);
        return new Image($resource);
    }

    protected function createControllerContextForDomain(string $domain): ControllerContext
    {
        $mockHttpRequest = new ServerRequest('GET', 'https://' . $domain);
        $parameters = $mockHttpRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $mockHttpRequest = $mockHttpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $parameters->withParameter('requestUriHost', $domain));
        $actionRequest = ActionRequest::fromHttpRequest($mockHttpRequest);
        $actionResponse = new ActionResponse();
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($actionRequest);
        return new ControllerContext($actionRequest, $actionResponse, new Arguments(), $uriBuilder);
    }

    protected function createSite(string $nodeName, string $domain): Site
    {
        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $domainRepository = $this->objectManager->get(DomainRepository::class);

        $this->sitesNode->createNode($nodeName, $this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:HomePage'));

        $site = new Site($nodeName);
        $site->setSiteResourcesPackageKey('NEOSidekick.AiAssistant');
        $site->setState(Site::STATE_ONLINE);
        $siteRepository->add($site);

        $domainModel = new Domain();
        $domainModel->setSite($site);
        $domainModel->setScheme('https');
        $domainModel->setHostname($domain);
        $domainRepository->add($domainModel);

        $site->getDomains()->add($domainModel);
        $site->setPrimaryDomain($domainModel);

        $siteRepository->update($site);

        $this->persistenceManager->persistAll();

        return $site;
    }
}

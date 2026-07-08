<?php

namespace NEOSidekick\AiAssistant\Tests\Functional;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\NodeAggregate\NodeName;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Configuration\ConfigurationManager;
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
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;

abstract class FunctionalTestCase extends \Neos\Flow\Tests\FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;
    protected array $dimensions = [];
    protected array $siteHosts = [];

    protected ContextFactory $contextFactory;
    protected ?NodeDataRepository $nodeDataRepository = null;
    protected WorkspaceRepository $workspaceRepository;
    protected ?\Neos\ContentRepository\Core\Projection\ContentGraph\Node $rootNode = null;
    protected ?\Neos\ContentRepository\Core\Projection\ContentGraph\Node $sitesNode = null;
    protected ?\Neos\ContentRepository\Core\SharedModel\Workspace\Workspace $liveWorkspace = null;
    protected ?\Neos\ContentRepository\Core\SharedModel\Workspace\Workspace $groupWorkspace = null;
    protected ?\Neos\ContentRepository\Core\NodeType\NodeTypeManager $nodeTypeManager = null;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $contentRepository->getNodeTypeManager() = $this->objectManager->get(\Neos\ContentRepository\Core\NodeType\NodeTypeManager::class);
        $this->currentUserWorkspace = explode('.', uniqid('user-', true))[0];
        $this->currentGroupWorkspace = explode('.', uniqid('group-', true))[0];
        $this->setUpRootNodeAndRepository();

        // Purge existing sites/domains and content under /sites to ensure tests are isolated
        $this->purgeSitesDomainsAndContent();

        foreach ($this->siteHosts as $siteHost) {
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
        try {
            $this->saveNodesAndTearDownRootNodeAndRepository();
        } finally {
            $this->restoreNodeServiceApiFacadeAfterTest();
            parent::tearDown();
        }
    }

    /**
     * Tests that replace ApiFacade with a mock must not leave the mock on the singleton NodeService.
     */
    protected function restoreNodeServiceApiFacadeAfterTest(): void
    {
        if (!isset($this->objectManager)) {
            return;
        }
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $this->objectManager->get(ApiFacade::class));
    }

    /**
     * Values persisted on NodeData for a language preset are the preset identifier as a single
     * dimension value (see ContextFactory::mergeDimensionValues), not the full YAML fallback chain.
     *
     * CAVEAT: This assumes every preset defines exactly its own identifier as the sole dimension
     * value — which holds for our current Testing/Settings.yaml presets ("de" → ['de'],
     * "en" → ['en']). If a preset were configured with a fallback chain (e.g. ['en', 'de']),
     * the value stored on NodeData would still be the full chain as created by
     * ContextFactory::mergeDimensionValues, and this helper would return the wrong result.
     * Revisit if the dimension configuration changes.
     */
    protected function getStoredLanguageDimensionValuesForPreset(string $presetIdentifier): array
    {
        return [$presetIdentifier];
    }

    /**
     * Preset "values" from Settings (fallback chain), sorted like NodeData — for assertions on
     * context paths produced by routing / FrontendNodeRoutePartHandler (findImportantPages).
     */
    protected function getRoutingLanguageDimensionValuesForPreset(string $presetKey): array
    {
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $presetConfig = $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'Neos.ContentRepository.contentDimensions.language.presets.' . $presetKey
        );
        $values = is_array($presetConfig) ? ($presetConfig['values'] ?? []) : [];
        sort($values);

        return $values;
    }

    protected function setUpRootNodeAndRepository(): void
    {
        $this->contextFactory = $this->objectManager->get(ContextFactory::class);

        $this->workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        if ($this->liveWorkspace === null) {
            $this->liveWorkspace = new \Neos\ContentRepository\Core\SharedModel\Workspace\Workspace('live');
            $this->workspaceRepository->add($this->liveWorkspace);
            $this->groupWorkspace = new \Neos\ContentRepository\Core\SharedModel\Workspace\Workspace($this->currentGroupWorkspace, $this->liveWorkspace);
            $this->workspaceRepository->add($this->groupWorkspace);
            $this->workspaceRepository->add(new \Neos\ContentRepository\Core\SharedModel\Workspace\Workspace($this->currentUserWorkspace, $this->groupWorkspace));
            $this->persistenceManager->persistAll();
        }

        $liveContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        $personalContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => $this->currentUserWorkspace]);

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
        $this->contextFactory->reset();
        // Routing (NodeFindingService) uses Neos ContentContextFactory; tests used to reset only the base CR
        // ContextFactory singleton, leaving stale contextInstances and breaking findImportantPages / URI resolve.
        $this->objectManager->get(ContentContextFactory::class)->reset();

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
        $liveContext = new \Neos\Rector\ContentRepository90\Legacy\LegacyContextStub(['workspaceName' => 'live']);
        $sitesNode = $liveContext->getNode('/sites');
        if ($sitesNode !== null) {
            foreach ($sitesNode->getChildNodes() as $child) {
                $child->remove();
            }
        }
        $this->persistenceManager->persistAll();
    }

    /**
     * @param \Neos\ContentRepository\Core\Projection\ContentGraph\Node $parentNode
     * @param string        $title
     * @param array         $imageFixtureFilenames
     *
     * @return \Neos\ContentRepository\Core\Projection\ContentGraph\Node
     * @throws \Neos\ContentRepository\Exception\NodeConfigurationException
     * @throws \Neos\ContentRepository\Exception\NodeException
     */
    protected function createPageWithImageNodes(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $parentNode, string $nodeName, string $title, array $imageFixtureFilenames): \Neos\ContentRepository\Core\Projection\ContentGraph\Node
    {
        /** @var \Neos\ContentRepository\Core\Projection\ContentGraph\Node $documentNode */
        $documentNode = $parentNode->createNodeFromTemplate($this->createDocumentNodeTemplate($title), $nodeName);
        // TODO 9.0 migration: !! Node::setProperty() is not supported by the new CR. Use the "SetNodeProperties" command to change property values.
        $documentNode->setProperty('uriPathSegment', $nodeName);
        $mainContentCollection = $documentNode->findNamedChildNode(\Neos\ContentRepository\Core\SharedModel\Node\NodeName::fromString('main'));
        foreach ($imageFixtureFilenames as $imageFixtureFilename) {
            $mainContentCollection->createNodeFromTemplate($this->createImageNodeTemplate($imageFixtureFilename), 'image-' . explode('.', $imageFixtureFilename)[0]);
        }
        return $documentNode;
    }

    protected function createDocumentNodeTemplate(string $title): NodeTemplate
    {
        // TODO 9.0 migration: !! NodeTemplate is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.
        $nodeTemplate = new NodeTemplate();
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $nodeTemplate->setNodeType($contentRepository->getNodeTypeManager()->getNodeType('NEOSidekick.AiAssistant.Testing:Page'));
        $nodeTemplate->setProperty('title', $title);
        return $nodeTemplate;
    }

    protected function createImageNodeTemplate(string $imageFixtureFilename): NodeTemplate
    {
        // TODO 9.0 migration: !! NodeTemplate is removed in Neos 9.0. Use the "CreateNodeAggregateWithNode" command to create new nodes or "CreateNodeVariant" command to create variants of an existing node in other dimensions.
        $nodeTemplate = new NodeTemplate();
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));
        $nodeTemplate->setNodeType($contentRepository->getNodeTypeManager()->getNodeType('NEOSidekick.AiAssistant.Testing:Image'));
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
        // TODO 9.0 migration: Make this code aware of multiple Content Repositories.

        $contentRepository = $this->contentRepositoryRegistry->get(\Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId::fromString('default'));

        $this->sitesNode->createNode($nodeName, $contentRepository->getNodeTypeManager()->getNodeType('NEOSidekick.AiAssistant.Testing:HomePage'));

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

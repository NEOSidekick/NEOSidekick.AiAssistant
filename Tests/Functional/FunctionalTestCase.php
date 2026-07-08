<?php

namespace NEOSidekick\AiAssistant\Tests\Functional;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\RootNodeCreation\Command\CreateRootNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodePath;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Service\NodeVisibility;

/**
 * Neos 9 rewrite of the functional test base: all content is created through content
 * repository commands (the old NodeData/Context API is gone). The two test languages are
 * the hosting distribution's dimension values LANGUAGE_DE ("de", uriSegment "de") and
 * LANGUAGE_EN ("en_US", uriSegment "en") — see Configuration/Testing/Settings.yaml for why.
 */
abstract class FunctionalTestCase extends \Neos\Flow\Tests\FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;

    public const LANGUAGE_DE = 'de';
    public const LANGUAGE_EN = 'en_US';

    /**
     * Hosts to create sites for; the site node name is the first host label (example.com → example).
     */
    protected array $siteHosts = [];

    protected string $currentUserWorkspace;
    protected string $currentGroupWorkspace;

    protected ContentRepositoryRegistry $contentRepositoryRegistry;
    protected ContentRepository $contentRepository;
    protected ?NodeAggregateId $sitesNodeAggregateId = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->contentRepositoryRegistry = $this->objectManager->get(ContentRepositoryRegistry::class);
        $contentRepositoryId = $this->objectManager->get(\NEOSidekick\AiAssistant\Service\ContentRepositoryProvider::class)->getContentRepositoryId();

        // Flow's testable persistence drops the non-ORM cr_* tables when it (re)compiles the
        // test schema, so the (idempotent) CR setup must run per test; prune isolates state.
        $maintainer = $this->contentRepositoryRegistry->buildService($contentRepositoryId, new ContentRepositoryMaintainerFactory());
        $setupError = $maintainer->setUp();
        self::assertNull($setupError, 'CR setup failed: ' . ($setupError?->getMessage() ?? ''));
        $pruneError = $maintainer->prune();
        self::assertNull($pruneError, 'CR prune failed: ' . ($pruneError?->getMessage() ?? ''));

        $this->contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);

        $this->contentRepository->handle(CreateRootWorkspace::create(WorkspaceName::forLive(), ContentStreamId::create()));

        $this->sitesNodeAggregateId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateRootNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $this->sitesNodeAggregateId,
            NodeTypeName::fromString('Neos.Neos:Sites')
        ));

        foreach ($this->siteHosts as $siteHost) {
            $this->createSite(explode('.', $siteHost)[0], $siteHost);
        }

        // Live content must exist BEFORE the user/group workspaces are created: event-sourced
        // workspaces fork their base's content stream at creation time and would not see any
        // live content created afterwards (unlike the Neos 8 context views).
        $this->setUpContentInLive();

        $this->currentGroupWorkspace = explode('.', uniqid('group-', true))[0];
        $this->currentUserWorkspace = explode('.', uniqid('user-', true))[0];
        $this->contentRepository->handle(CreateWorkspace::create(
            WorkspaceName::fromString($this->currentGroupWorkspace),
            WorkspaceName::forLive(),
            ContentStreamId::create()
        ));
        $this->contentRepository->handle(CreateWorkspace::create(
            WorkspaceName::fromString($this->currentUserWorkspace),
            WorkspaceName::fromString($this->currentGroupWorkspace),
            ContentStreamId::create()
        ));

        $this->setUpContentInUserWorkspace();
    }

    /**
     * Template method: create the live base content here (runs before the workspaces fork live).
     */
    protected function setUpContentInLive(): void
    {
    }

    /**
     * Template method: create user-workspace-only content here (variants etc.).
     */
    protected function setUpContentInUserWorkspace(): void
    {
    }

    public function tearDown(): void
    {
        try {
            $this->restoreNodeServiceApiFacadeAfterTest();
        } finally {
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

    protected function dimensionSpacePoint(string $language = self::LANGUAGE_DE): DimensionSpacePoint
    {
        return DimensionSpacePoint::fromArray(['language' => $language]);
    }

    /**
     * Backend-like subgraph (disabled nodes visible) — content creation and assertions on
     * raw structure should not silently miss disabled nodes.
     */
    protected function subgraph(string $workspace = 'live', string $language = self::LANGUAGE_DE): ContentSubgraphInterface
    {
        return $this->contentRepository
            ->getContentGraph(WorkspaceName::fromString($workspace))
            ->getSubgraph($this->dimensionSpacePoint($language), NodeVisibility::excludeRemoved());
    }

    /**
     * Resolves an old-style path like "/sites/example/some-page" and returns the node.
     */
    protected function getNodeByPath(string $path, string $workspace = 'live', string $language = self::LANGUAGE_DE): ?Node
    {
        $relativePath = ltrim($path, '/');
        if ($relativePath === 'sites' || $relativePath === '') {
            return $this->subgraph($workspace, $language)->findNodeById($this->sitesNodeAggregateId);
        }
        $relativePath = preg_replace('#^sites/#', '', $relativePath);

        return $this->subgraph($workspace, $language)->findNodeByPath(
            NodePath::fromString($relativePath),
            $this->sitesNodeAggregateId
        );
    }

    /**
     * NodeAddress JSON of the node at the given old-style path — the result array key format
     * of NodeService::find()/findImportantPages() (replaces the old context path assertions).
     */
    protected function addressForPath(string $path, string $workspace = 'live', string $language = self::LANGUAGE_DE): string
    {
        $node = $this->getNodeByPath($path, $workspace, $language);
        $this->assertNotNull($node, sprintf('Node at path "%s" (%s, %s) not found', $path, $workspace, $language));

        // Result keys carry the *requested* workspace, even for nodes inherited from base workspaces
        return NodeAddress::create(
            $this->contentRepository->id,
            WorkspaceName::fromString($workspace),
            $this->dimensionSpacePoint($language),
            $node->aggregateId
        )->toJson();
    }

    protected function createSite(string $nodeName, string $domain): Site
    {
        $siteNodeAggregateId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $siteNodeAggregateId,
            NodeTypeName::fromString('NEOSidekick.AiAssistant.Testing:HomePage'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $this->sitesNodeAggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray([
                'title' => $nodeName,
                'uriPathSegment' => $nodeName,
            ])
        )->withNodeName(NodeName::fromString($nodeName)));

        $siteRepository = $this->objectManager->get(SiteRepository::class);
        $domainRepository = $this->objectManager->get(DomainRepository::class);

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

    /**
     * Creates a Testing:Page document (tethered "main" collection included) with the given
     * image content nodes, in the live workspace, origin language "de".
     */
    protected function createPageWithImageNodes(Node $parentNode, string $nodeName, string $title, array $imageFixtureFilenames): Node
    {
        $documentNodeAggregateId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $documentNodeAggregateId,
            NodeTypeName::fromString('NEOSidekick.AiAssistant.Testing:Page'),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $parentNode->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray([
                'title' => $title,
                'uriPathSegment' => $nodeName,
            ])
        )->withNodeName(NodeName::fromString($nodeName)));

        $subgraph = $this->subgraph();
        $mainContentCollection = $subgraph->findNodeByPath(NodeName::fromString('main'), $documentNodeAggregateId);
        $this->assertNotNull($mainContentCollection, 'Tethered "main" child was not created');

        foreach ($imageFixtureFilenames as $imageFixtureFilename) {
            $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
                WorkspaceName::forLive(),
                NodeAggregateId::create(),
                NodeTypeName::fromString('NEOSidekick.AiAssistant.Testing:Image'),
                OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
                $mainContentCollection->aggregateId,
                initialPropertyValues: PropertyValuesToWrite::fromArray([
                    'image' => $this->importImage($imageFixtureFilename),
                ])
            )->withNodeName(NodeName::fromString('image-' . explode('.', $imageFixtureFilename)[0])));
        }

        return $subgraph->findNodeById($documentNodeAggregateId);
    }

    protected function setNodeProperties(Node $node, array $properties, string $workspace = 'live'): void
    {
        $this->contentRepository->handle(SetNodeProperties::create(
            WorkspaceName::fromString($workspace),
            $node->aggregateId,
            $node->originDimensionSpacePoint,
            PropertyValuesToWrite::fromArray($properties)
        ));
    }

    /**
     * Creates a language variant of the node (replaces the old createVariantForContext()).
     */
    protected function createLanguageVariant(Node $node, string $targetLanguage, string $workspace = 'live'): void
    {
        $this->contentRepository->handle(CreateNodeVariant::create(
            WorkspaceName::fromString($workspace),
            $node->aggregateId,
            $node->originDimensionSpacePoint,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint($targetLanguage))
        ));
    }

    /**
     * Replaces the old setHidden(true) — disables the node (subtree tag) in the given workspace.
     */
    protected function disableNode(Node $node, string $workspace): void
    {
        $this->contentRepository->handle(TagSubtree::create(
            WorkspaceName::fromString($workspace),
            $node->aggregateId,
            $node->originDimensionSpacePoint->toDimensionSpacePoint(),
            NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            SubtreeTag::disabled()
        ));
    }

    /**
     * Replaces the old Node::remove() — removes the node aggregate variant in the given workspace.
     */
    protected function removeNode(Node $node, string $workspace = 'live'): void
    {
        $this->contentRepository->handle(\Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate::create(
            WorkspaceName::fromString($workspace),
            $node->aggregateId,
            $node->originDimensionSpacePoint->toDimensionSpacePoint(),
            NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS
        ));
    }

    /**
     * Publishes the user workspace through its base chain up to live
     * (the new CR only publishes one level at a time).
     */
    protected function publishUserWorkspaceToLive(): void
    {
        $this->contentRepository->handle(PublishWorkspace::create(WorkspaceName::fromString($this->currentUserWorkspace)));
        $this->contentRepository->handle(PublishWorkspace::create(WorkspaceName::fromString($this->currentGroupWorkspace)));
    }

    private function importImage(string $fixtureFilename): Image
    {
        $resource = $this->objectManager->get(ResourceManager::class)->importResource(__DIR__ . '/../Fixtures/' . $fixtureFilename);
        $image = new Image($resource);
        // the CR property serializer stores the asset identifier, so the image must be persisted
        $this->objectManager->get(AssetRepository::class)->add($image);
        $this->persistenceManager->persistAll();

        return $image;
    }

    protected function createControllerContextForDomain(string $domain): ControllerContext
    {
        $mockHttpRequest = new ServerRequest('GET', 'https://' . $domain);
        $parameters = $mockHttpRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $parameters = $parameters->withParameter('requestUriHost', $domain);
        // Neos 9: NodeUriBuilder requires the SiteDetectionResult the SiteDetectionMiddleware
        // would add on a real request (site node name = first host label, see createSite()).
        $parameters = \Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult::create(
            \Neos\Neos\Domain\Model\SiteNodeName::fromString(explode('.', $domain)[0]),
            $this->contentRepository->id
        )->storeInRouteParameters($parameters);
        $mockHttpRequest = $mockHttpRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $parameters);
        $actionRequest = ActionRequest::fromHttpRequest($mockHttpRequest);
        $actionResponse = new ActionResponse();
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($actionRequest);
        return new ControllerContext($actionRequest, $actionResponse, new Arguments(), $uriBuilder);
    }

    /**
     * The uriPathSuffix now lives in the site configuration (Neos 9), not in Flow routes settings.
     */
    protected function getUriPathSuffix(): string
    {
        return '.html';
    }
}

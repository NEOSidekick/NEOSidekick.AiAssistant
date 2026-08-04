<?php

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Dto\MatchResult;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\FrontendRouting\FrontendNodeRoutePartHandlerInterface;
use Neos\Neos\FrontendRouting\SiteDetection\SiteDetectionResult;
use Neos\Neos\Routing\Exception\NoSiteException;

class NodeFindingService
{
    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Resolves a public URI to the corresponding document node in the given target workspace.
     *
     * The URI path is matched through Neos' frontend route part handler
     * ({@see \Neos\Neos\FrontendRouting\EventSourcedFrontendNodeRoutePartHandler}), which yields a
     * NodeAddress in the live workspace (including dimension resolution for uriSegment prefixes).
     * The node is then looked up in the target workspace's content graph at the resolved
     * dimension space point.
     *
     * @param mixed  $term
     * @param string $targetWorkspaceName
     *
     * @return Node|null
     */
    public function tryToResolvePublicUriToNode(mixed $term, string $targetWorkspaceName): ?Node
    {
        if (!preg_match('/(https?:\/\/(?:www\.|(?!www))[a-zA-Z0-9][a-zA-Z0-9-]+[a-zA-Z0-9]\.[^\s]{2,}|www\.[a-zA-Z0-9][a-zA-Z0-9-]+[a-zA-Z0-9]\.[^\s]{2,}|https?:\/\/(?:www\.|(?!www))[a-zA-Z0-9]+\.[^\s]{2,}|www\.[a-zA-Z0-9]+\.[^\s]{2,})/', $term)) {
            return null;
        }

        $uri = new Uri($term);
        $path = str_starts_with($uri->getPath(), '/') ? substr($uri->getPath(), 1) : $uri->getPath();
        $path = rtrim($path, '/');

        try {
            $site = $this->siteService->getSiteByHostName($uri->getHost());
        } catch (NoSiteException) {
            return null;
        }

        $uriPathSuffix = $site->getConfiguration()->uriPathSuffix;

        $routeParameters = RouteParameters::createEmpty()
            ->withParameter('requestUriHost', $uri->getHost());
        // The frontend route part handler requires the SiteDetectionResult (site + content repository)
        // in the route parameters; in a regular request the SiteDetectionMiddleware provides it.
        $routeParameters = SiteDetectionResult::create(
            $site->getNodeName(),
            $site->getConfiguration()->contentRepositoryId
        )->storeInRouteParameters($routeParameters);

        $matchResult = $this->matchPathWithRouteHandler($path, $uriPathSuffix, $routeParameters);

        // Retry with the configured suffix appended — handles URLs that were
        // stored/returned without it (e.g. bare paths or trailing-slash variants).
        if ((!$matchResult || !$matchResult->getMatchedValue()) && $uriPathSuffix !== '' && !str_ends_with($path, $uriPathSuffix)) {
            $matchResult = $this->matchPathWithRouteHandler($path . $uriPathSuffix, $uriPathSuffix, $routeParameters);
        }

        if (!$matchResult || !$matchResult->getMatchedValue()) {
            return null;
        }

        // The route part handler matches in the live workspace; transfer the resolved address
        // (aggregate id + dimension space point) into the requested target workspace.
        try {
            $nodeAddressInLiveWorkspace = NodeAddress::fromJsonString((string)$matchResult->getMatchedValue());
        } catch (\InvalidArgumentException) {
            return null;
        }

        $contentRepository = $this->contentRepositoryRegistry->get($nodeAddressInLiveWorkspace->contentRepositoryId);
        $targetWorkspace = $contentRepository->findWorkspaceByName(WorkspaceName::fromString($targetWorkspaceName));
        if ($targetWorkspace === null) {
            return null;
        }

        $subgraph = $contentRepository->getContentGraph($targetWorkspace->workspaceName)
            ->getSubgraph($nodeAddressInLiveWorkspace->dimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved());

        return $subgraph->findNodeById($nodeAddressInLiveWorkspace->aggregateId);
    }

    /**
     * @return MatchResult|false
     */
    private function matchPathWithRouteHandler(string $path, string $uriPathSuffix, RouteParameters $routeParameters)
    {
        $routeHandler = $this->objectManager->get(FrontendNodeRoutePartHandlerInterface::class);
        $routeHandler->setName('node');
        $routeHandler->setOptions(['uriPathSuffix' => $uriPathSuffix]);
        return $routeHandler->matchWithParameters($path, $routeParameters);
    }
}

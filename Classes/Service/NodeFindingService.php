<?php

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Dto\MatchResult;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Routing\FrontendNodeRoutePartHandlerInterface;

class NodeFindingService
{
    use CreateContentContextTrait;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="mvc.routes")
     * @var array
     */
    protected array $routesConfiguration;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
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

        $uriPathSuffix = (string)($this->routesConfiguration['Neos.Neos']['variables']['defaultUriSuffix'] ?? '');

        $routeParameters = RouteParameters::createEmpty();
        $routeParameters = $routeParameters->withParameter('requestUriHost', $uri->getHost());

        $matchResult = $this->matchPathWithRouteHandler($path, $uriPathSuffix, $routeParameters);

        // Retry with the configured suffix appended — handles URLs that were
        // stored/returned without it (e.g. bare paths or trailing-slash variants).
        if ((!$matchResult || !$matchResult->getMatchedValue()) && $uriPathSuffix !== '' && !str_ends_with($path, $uriPathSuffix)) {
            $matchResult = $this->matchPathWithRouteHandler($path . $uriPathSuffix, $uriPathSuffix, $routeParameters);
        }

        if (!$matchResult || !$matchResult->getMatchedValue()) {
            return null;
        }

        $nodeContextPath = $matchResult->getMatchedValue();
        $nodeContextPathSegments = NodePaths::explodeContextPath($nodeContextPath);
        $nodePath = $nodeContextPathSegments['nodePath'];
        $context = $this->createContentContext($targetWorkspaceName, $nodeContextPathSegments['dimensions']);
        $matchingNode = $context->getNode($nodePath);

        if (!$matchingNode) {
            return null;
        }

        return $matchingNode;
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

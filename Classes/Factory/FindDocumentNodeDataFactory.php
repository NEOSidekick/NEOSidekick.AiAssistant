<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Service\NodeVisibility;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;

/**
 * @Flow\Scope("singleton")
 */
class FindDocumentNodeDataFactory
{
    /**
     * @Flow\Inject
     * @var NodeUriBuilderFactory
     */
    protected $nodeUriBuilderFactory;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected string $languageDimensionName;

    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @throws NoMatchingRouteException
     */
    public function createFromNode(Node $node, ControllerContext $controllerContext): FindDocumentNodeData
    {
        $nodeAddress = NodeAddress::fromNode($node);
        $nodeUriBuilder = $this->nodeUriBuilderFactory->forActionRequest($controllerContext->getRequest());
        // For non-live workspaces uriFor() falls back to an absolute preview uri automatically
        $publicUri = $previewUri = (string)$nodeUriBuilder->uriFor($nodeAddress, Options::createForceAbsolute());

        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $workspace = $contentRepository->findWorkspaceByName($node->workspaceName);
        if ($workspace !== null && !$workspace->isRootWorkspace()) {
            $nodeInLiveWorkspace = $contentRepository->getContentGraph(WorkspaceName::forLive())
                ->getSubgraph($node->dimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved())
                ->findNodeById($node->aggregateId);
            if ($nodeInLiveWorkspace !== null) {
                try {
                    $publicUri = (string)$nodeUriBuilder->uriFor(NodeAddress::fromNode($nodeInLiveWorkspace), Options::createForceAbsolute());
                } catch (NoMatchingRouteException) {
                    // The node exists in live but no public route can be built (e.g. invalid shortcut target);
                    // keep the preview uri as public uri, like the old LinkingService-based fallback behavior.
                }
            }
        }

        // The language shown/edited is the dimension the node is SERVED in (subgraph dimension):
        // for fallback pages (/uk serving en_US content) this is en_UK, like the old CR context
        // dimensions — the origin would mislabel every fallback row with its source language.
        $language = $node->dimensionSpacePoint->getCoordinate(new ContentDimensionId($this->languageDimensionName)) ?: 'de';

        return new FindDocumentNodeData(
            // Keeps the old "<identifier>-<dimensionsHash>" shape with the Neos 9 equivalents
            sprintf('%s-%s', $node->aggregateId->value, $node->originDimensionSpacePoint->hash),
            // Decision: the "nodeContextPath" DTO field now carries the NodeAddress JSON. The JS side treats it
            // as an opaque id and round-trips it back to the backend (NodeService::updatePropertiesOnNodes,
            // NodeWithImageService), where it is parsed via NodeAddress::fromJsonString().
            $nodeAddress->toJson(),
            $node->nodeTypeName->value,
            $publicUri,
            $previewUri,
            iterator_to_array($node->properties),
            $language
        );
    }
}

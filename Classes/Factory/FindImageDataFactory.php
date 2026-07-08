<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use NEOSidekick\AiAssistant\Dto\FindImageData;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;

/**
 * @Flow\Scope("singleton")
 */
class FindImageDataFactory
{
    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;

    /**
     * @Flow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ContentRepositoryRegistry
     */
    protected $contentRepositoryRegistry;

    /**
     * @param Node                               $node
     * @param NodeTypeWithImageMetadataSchemaDto $schema
     * @param ControllerContext                  $controllerContext
     *
     * @return FindImageData|null
     * @throws Exception
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws AssetServiceException
     * @throws ThumbnailServiceException
     */
    public function createFromNodeAndSchema(Node $node, NodeTypeWithImageMetadataSchemaDto $schema, ControllerContext $controllerContext): ?FindImageData
    {
        $asset = $node->getProperty($schema->getImagePropertyName());

        if (!$asset) {
            return null;
        }

        // Remark: the "async" parameter here is extremely important, otherwise it will try to generate all thumbnails and the request will take ages
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview', true);
        // todo we directly access the array offset "src" -> we need a better check or accept an exception
        $thumbnailUri = $this->assetService->getThumbnailUriAndSizeForAsset($asset, $thumbnailConfiguration, $controllerContext->getRequest())['src'];
        $fullsizeUri = $this->resourceManager->getPublicPersistentResourceUri($asset->getResource());
        $alternativeTextPropertyName = $schema->getAlternativeTextPropertyName();
        $titleTextPropertyName = $schema->getTitleTextPropertyName();

        return new FindImageData(
            NodeAddress::fromNode($node)->toJson(),
            $node->nodeTypeName->value,
            $this->resolveNodeOrderIndex($node),
            $asset->getResource()->getFilename(),
            $fullsizeUri,
            $thumbnailUri,
            $schema->getImagePropertyName(),
            $alternativeTextPropertyName,
            $alternativeTextPropertyName ? $node->getProperty($alternativeTextPropertyName) : null,
            $titleTextPropertyName,
            $titleTextPropertyName ? $node->getProperty($titleTextPropertyName) : null
        );
    }

    /**
     * TODO 9.0 migration (manual): Node::getIndex() is removed; we return the node's position among its
     * siblings, which preserves the relative ordering but not the legacy sorting index values.
     */
    private function resolveNodeOrderIndex(Node $node): int
    {
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $parentNode = $subgraph->findParentNode($node->aggregateId);
        if ($parentNode === null) {
            return 0;
        }

        $position = 0;
        foreach ($subgraph->findChildNodes($parentNode->aggregateId, FindChildNodesFilter::create()) as $siblingNode) {
            if ($siblingNode->aggregateId->equals($node->aggregateId)) {
                return $position;
            }
            $position++;
        }

        return 0;
    }
}

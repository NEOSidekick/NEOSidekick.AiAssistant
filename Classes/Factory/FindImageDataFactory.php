<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
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
     * @param Node                               $node
     * @param NodeTypeWithImageMetadataSchemaDto $schema
     * @param ControllerContext                  $controllerContext
     *
     * @return FindImageData|null
     * @throws Exception
     * @throws MissingActionNameException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
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
        return new FindImageData(
            $node->getContextPath(),
            $node->getNodeType()->getName(),
            $node->getIndex(),
            $asset->getResource()->getFilename(),
            $fullsizeUri,
            $thumbnailUri,
            $schema->getImagePropertyName(),
            $schema->getAlternativeTextPropertyName(),
            $node->getProperty($schema->getAlternativeTextPropertyName()),
            $schema->getTitleTextPropertyName(),
            $node->getProperty($schema->getTitleTextPropertyName())
        );
    }
}

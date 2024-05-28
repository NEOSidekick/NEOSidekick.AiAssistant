<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Service\AssetService;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindAssetData;

/**
 * @Flow\Scope("singleton")
 */
class FindAssetItemDtoFactory
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
     * @throws ThumbnailServiceException
     * @throws PropertyNotAccessibleException
     * @throws MissingActionNameException
     * @throws AssetServiceException
     * @throws Exception
     */
    public function create(Asset $asset, ControllerContext $controllerContext): FindAssetData
    {
        // Remark: the "async" parameter here is extremely important, otherwise it will try to generate all thumbnails and the request will take ages
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview', true);
        // todo we directly access the array offset "src" -> we need a better check or accept an exception
        $thumbnailUri = $this->assetService->getThumbnailUriAndSizeForAsset($asset, $thumbnailConfiguration, $controllerContext->getRequest())['src'];
        $fullsizeUri = $this->resourceManager->getPublicPersistentResourceUri($asset->getResource());
        $properties = [
            "title" => ObjectAccess::getProperty($asset, 'title'),
            "caption" => ObjectAccess::getProperty($asset, 'caption'),
            "copyrightNotice" => ObjectAccess::getProperty($asset, 'copyrightNotice'),
        ];

        return new FindAssetData(
            $asset->getResource()->getFilename(),
            $asset->getIdentifier(),
            $thumbnailUri,
            $fullsizeUri,
            $properties
        );
    }
}

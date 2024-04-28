<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindAssetData;
use NEOSidekick\AiAssistant\Dto\UpdateAssetData;

/**
 * @Flow\Scope("singleton")
 */
class FindAssetItemDtoFactory
{
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

    public function create(Asset $asset): FindAssetData
    {
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview');
        $thumbnail = $this->thumbnailService->getThumbnail($asset, $thumbnailConfiguration);
        $thumbnailUri = $this->thumbnailService->getUriForThumbnail($thumbnail);
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

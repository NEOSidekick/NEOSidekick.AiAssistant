<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\AssetModuleResultDto;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;

/**
 * @Flow\Scope("singleton")
 */
class AssetModuleResultDtoFactory
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

    public function create(Asset $asset, AssetModuleConfigurationDto $configuration): AssetModuleResultDto
    {
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview');
        $thumbnail = $this->thumbnailService->getThumbnail($asset, $thumbnailConfiguration);
        $thumbnailUri = $this->thumbnailService->getUriForThumbnail($thumbnail);
        $fullsizeUri = $this->resourceManager->getPublicPersistentResourceUri($asset->getResource());

        try {
            return new AssetModuleResultDto(
                $asset->getResource()->getFilename(),
                $asset->getIdentifier(),
                $thumbnailUri,
                $fullsizeUri,
                $configuration->getPropertyName(),
                ObjectAccess::getProperty($asset, $configuration->getPropertyName())
            );
        } catch (PropertyNotAccessibleException) {
            // This cannot happen, as we already validate the propertyName
            // already in our AssetModuleConfigurationDto
        }
    }
}

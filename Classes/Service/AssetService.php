<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\AssetModuleResultDto;
use NEOSidekick\AiAssistant\Factory\AssetModuleResultDtoFactory;

/**"
 * @Flow\Scope("singleton")
 */
class AssetService
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetModuleResultDtoFactory
     */
    protected $assetModuleResultDtoFactory;

    /**
     * @param AssetModuleConfigurationDto $configurationDto
     *
     * @return array<AssetModuleResultDto>
     */
    public function getAssetsThatNeedProcessing(AssetModuleConfigurationDto $configurationDto): array
    {
        $assetsIterator = $this->assetRepository->findAllIterator();
        $assetsThatNeedProcessing = [];
        $assetsThatNeedProcessingCount = 0;
        $iteratedItems = 0;

        foreach ($this->assetRepository->iterate($assetsIterator) as $currentAsset) {
            $iteratedItems++;
            if ($iteratedItems <= $configurationDto->getFirstResult()) {
                continue;
            }

            if ($assetsThatNeedProcessingCount >= $configurationDto->getLimit()) {
                break;
            }
            if (!$currentAsset instanceof Image) {
                continue;
            }
            try {
                $propertyValue = ObjectAccess::getProperty($currentAsset, $configurationDto->getPropertyName());
            } catch (PropertyNotAccessibleException $e) {
                continue;
            }
            if (!empty($propertyValue)) {
                continue;
            }
            if ($configurationDto->isOnlyAssetsInUse() && $currentAsset->getUsageCount() === 0) {
                continue;
            }

            $assetsThatNeedProcessing[] = $this->assetModuleResultDtoFactory->create(
                $currentAsset,
                $configurationDto
            );
            $assetsThatNeedProcessingCount++;
        }
        return $assetsThatNeedProcessing;
    }

    /**
     * @param array<AssetModuleResultDto> $resultDtos
     *
     * @return void
     */
    public function updateMultipleAssets(array $resultDtos): void
    {
        foreach ($resultDtos as $resultDto) {
            if (!$resultDto instanceof AssetModuleResultDto) {
                continue;
                // or throw exception?
            }

            $this->updateAsset($resultDto);
        }
    }

    public function updateAsset(AssetModuleResultDto $resultDto): void
    {
        /** @var Asset $asset */
        $asset = $this->assetRepository->findByIdentifier($resultDto->getIdentifier());

        if ($asset) {
            ObjectAccess::setProperty($asset, $resultDto->getPropertyName(), $resultDto->getPropertyValue());
            $this->assetRepository->update($asset);
        }
    }
}

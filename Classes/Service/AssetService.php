<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\Internal\Hydration\HydrationException;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetInterface;
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
    protected AssetRepository $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetModuleResultDtoFactory
     */
    protected AssetModuleResultDtoFactory $assetModuleResultDtoFactory;

    /**
     * @param AssetModuleConfigurationDto $configurationDto
     *
     * @return array<AssetInterface>
     */
    public function getAssetsThatNeedProcessing(AssetModuleConfigurationDto $configurationDto): array
    {
        $assetsIterator = $this->assetRepository->findAllIterator();
        $assetsThatNeedProcessing = [];
        $i = 0;
        foreach ($this->assetRepository->iterate($assetsIterator) as $currentAsset) {
            if ($i >= $configurationDto->getLimit()) {
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
            $i++;
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
        $asset = $this->assetRepository->findByIdentifier($resultDto->getAssetIdentifier());

        if ($asset) {
            ObjectAccess::setProperty($asset, $resultDto->getPropertyName(), $resultDto->getPropertyValue());
            $this->assetRepository->update($asset);
        }
    }
}

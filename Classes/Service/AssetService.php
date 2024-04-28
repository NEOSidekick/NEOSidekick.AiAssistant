<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindAssetsFilterDto;
use NEOSidekick\AiAssistant\Dto\UpdateAssetData;
use NEOSidekick\AiAssistant\Factory\FindAssetItemDtoFactory;

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
     * @var FindAssetItemDtoFactory
     */
    protected $assetModuleResultDtoFactory;

    /**
     * @param FindAssetsFilterDto $filters
     *
     * @return array<FindAssetData>
     */
    public function findImages(FindAssetsFilterDto $filters): array
    {
        $assetsIterator = $this->assetRepository->findAllIterator();
        $result = [];
        $resultCount = 0;
        $iteratedItems = 0;

        foreach ($this->assetRepository->iterate($assetsIterator) as $currentAsset) {
            $iteratedItems++;
            if ($iteratedItems <= $filters->getFirstResult()) {
                continue;
            }

            if ($resultCount >= $filters->getLimit()) {
                break;
            }

            if (!$currentAsset instanceof Image) {
                continue;
            }

            if (!empty($filters->getPropertyNameMustBeEmpty())) {
                try {
                    $propertyValue = ObjectAccess::getProperty($currentAsset, $filters->getPropertyNameMustBeEmpty());
                } catch (PropertyNotAccessibleException $e) {
                    continue;
                }
                if (!empty($propertyValue)) {
                    continue;
                }
            }

            if ($filters->isOnlyAssetsInUse() && $currentAsset->getUsageCount() === 0) {
                continue;
            }

            $result[] = $this->assetModuleResultDtoFactory->create($currentAsset);
            $resultCount++;
        }
        return $result;
    }

    /**
     * @param array<UpdateAssetData> $updateAssetsData
     *
     * @return void
     */
    public function updateMultipleAssets(array $updateAssetsData): void
    {
        foreach ($updateAssetsData as $updateAssetData) {
            if (!$updateAssetData instanceof UpdateAssetData) {
                throw new \InvalidArgumentException('Asset item data did not jach the expected type.');
            }

            $this->updateAsset($updateAssetData);
        }
    }

    public function updateAsset(UpdateAssetData $updateAssetData): void
    {
        /** @var Asset $asset */
        $asset = $this->assetRepository->findByIdentifier($updateAssetData->getIdentifier());

        if ($asset) {
            foreach ($updateAssetData->getProperties() as $propertyName => $propertyValue) {
                ObjectAccess::setProperty($asset, $propertyName, $propertyValue);
            }
            $this->assetRepository->update($asset);
        }
    }
}

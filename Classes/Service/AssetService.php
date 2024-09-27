<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\Internal\Hydration\IterableResult;
use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Persistence\Doctrine\Query;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetVariantInterface;
use Neos\Media\Domain\Model\Image;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use Neos\Utility\Exception\PropertyNotAccessibleException;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Dto\FindAssetData;
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
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @param FindAssetsFilterDto $filters
     * @param ControllerContext   $controllerContext
     *
     * @return array<FindAssetData>
     * @throws AssetServiceException
     * @throws Exception
     * @throws MissingActionNameException
     * @throws PropertyNotAccessibleException
     * @throws ThumbnailServiceException
     */
    public function findImages(FindAssetsFilterDto $filters, ControllerContext $controllerContext): array
    {
        $assetsIterator = $this->findInRepositoryMatchingFilterIterator($filters);
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

            if ($filters->isOnlyAssetsInUse() && $currentAsset->getUsageCount() === 0) {
                continue;
            }

            $result[] = $this->assetModuleResultDtoFactory->create($currentAsset, $controllerContext);
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
                throw new InvalidArgumentException('Asset item data did not match the expected type.');
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

    protected function findInRepositoryMatchingFilterIterator(FindAssetsFilterDto $filters): IterableResult
    {
        /** @var Query $query */
        $query = $this->persistenceManager->createQueryForType(Image::class);
        $this->addAssetVariantToQueryConstraints($query);

        if (!empty($filters->getPropertyNameMustBeEmpty())) {
            $query->matching($query->logicalAnd([$query->equals($filters->getPropertyNameMustBeEmpty(), ''), $query->getConstraint()]));
        }
        $query->setOrderings(['lastModified' => 'ASC']);

        return $query->getQueryBuilder()->getQuery()->iterate();
    }

    /**
     * Taken from: Neos\Media\Domain\Repository\AssetRepository
     *
     * Adds conditions filtering any implementation of AssetVariantInterface
     *
     * @param Query $query
     * @return void
     */
    protected function addAssetVariantToQueryConstraints(QueryInterface $query): void
    {
        $variantsConstraints = [];
        $variantClassNames = $this->reflectionService->getAllImplementationClassNamesForInterface(AssetVariantInterface::class);
        foreach ($variantClassNames as $variantClassName) {
            $variantsConstraints[] = 'e NOT INSTANCE OF ' . $variantClassName;
        }

        $constraints = $query->getConstraint();
        $query->matching($query->logicalAnd([$constraints, $query->logicalAnd($variantsConstraints)]));
    }
}

<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Doctrine\Common\Collections\ArrayCollection;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\NoSuchArgumentException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Property\TypeConverter\ArrayObjectConverter;
use Neos\Flow\Property\TypeConverter\ObjectConverter;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\AssetModuleResultDto;
use NEOSidekick\AiAssistant\Service\AssetService;

class BackendServiceController extends ActionController
{
    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected AssetService $assetService;
    protected $defaultViewObjectName = JsonView::class;
    protected $supportedMediaTypes = ['application/json'];

    public function initializeIndexAction()
    {
        $propertyMappingConfiguration = $this->arguments->getArgument('configuration')->getPropertyMappingConfiguration();
        $propertyMappingConfiguration->allowProperties('propertyName');
        $propertyMappingConfiguration->allowProperties('onlyAssetsInUse');
        $propertyMappingConfiguration->allowProperties('limit');
        $propertyMappingConfiguration->allowProperties('language');
    }

    /**
     * @return void
     */
    public function indexAction(AssetModuleConfigurationDto $configuration = null): void
    {
        if (!$configuration) {
            $configuration = new AssetModuleConfigurationDto(false, 'title', 5, 'en');
        }
        $nextTenAssetsToBeProcessed = $this->assetService->getAssetsThatNeedProcessing($configuration);
        $this->view->assign('value', $nextTenAssetsToBeProcessed);
    }

    public function initializeUpdateAction(): void
    {
        try {
            $propertyMappingConfiguration =
                $this->arguments->getArgument('resultDtos')->getPropertyMappingConfiguration();
            $propertyMappingConfiguration->allowAllProperties();
        } catch (NoSuchArgumentException $e) {
            // This cannot happen, otherwise we have a broken
            // request anyways
        }
    }

    /**
     * @param array<AssetModuleResultDto> $resultDtos
     *
     * @return void
     */
    public function updateAction(array $resultDtos): void
    {
        $this->assetService->updateMultipleAssets($resultDtos);
        $this->view->assign('value', $resultDtos);
    }
}

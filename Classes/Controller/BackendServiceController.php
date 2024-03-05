<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Exception;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\NoSuchArgumentException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Property\PropertyMappingConfiguration;
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
    protected $assetService;
    protected $defaultViewObjectName = JsonView::class;
    protected $supportedMediaTypes = ['application/json'];

    public function initializeIndexAction(): void
    {
        try {
            $propertyMappingConfiguration = $this->arguments->getArgument('configuration')
                ->getPropertyMappingConfiguration();
            $propertyMappingConfiguration->allowProperties('propertyName');
            $propertyMappingConfiguration->allowProperties('onlyAssetsInUse');
            $propertyMappingConfiguration->allowProperties('limit');
            $propertyMappingConfiguration->allowProperties('language');
        } catch (NoSuchArgumentException) {
            // This cannot happen, otherwise we have a broken
            // request anyway
        }
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
            $propertyMappingConfiguration
                ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
                ->skipProperties('generating', 'persisting', 'persisted')
                ->allowAllProperties();
        } catch (NoSuchArgumentException $e) {
            // This cannot happen, otherwise we have a broken
            // request anyway
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

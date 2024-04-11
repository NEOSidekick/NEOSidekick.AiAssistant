<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\NoSuchArgumentException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Property\PropertyMappingConfiguration;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\AssetModuleResultDto;
use NEOSidekick\AiAssistant\Dto\FocusKeywordModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\FocusKeywordModuleResultDto;
use NEOSidekick\AiAssistant\Service\AssetService;
use NEOSidekick\AiAssistant\Service\NodeService;

class BackendServiceController extends ActionController
{
    /**
     * @Flow\Inject
     * @var AssetService
     */
    protected $assetService;
    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;
    protected $supportedMediaTypes = ['application/json'];

    public function initializeAction()
    {
        $this->response->setContentType('application/json');
    }

    public function initializeIndexAction(): void
    {
        try {
            $propertyMappingConfiguration = $this->arguments->getArgument('configuration')
                ->getPropertyMappingConfiguration()
                ->allowProperties('propertyName', 'onlyAssetsInUse', 'limit', 'language', 'firstResult');
        } catch (NoSuchArgumentException) {
            // This cannot happen, otherwise we have a broken
            // request anyway
        }
    }

    /**
     * @return void
     */
    public function indexAction(AssetModuleConfigurationDto $configuration = null): string
    {
        if (!$configuration) {
            $configuration = new AssetModuleConfigurationDto(false, 'title', 5, 'en');
        }
        $resultCollection = $this->assetService->getAssetsThatNeedProcessing($configuration);
        return json_encode($resultCollection);
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
    public function updateAction(array $resultDtos): string
    {
        $this->assetService->updateMultipleAssets($resultDtos);
        return json_encode(array_map(fn (AssetModuleResultDto $item) => $item->jsonSerialize(), $resultDtos));
    }

    public function initializeGetFocusKeywordNodesAction(): void
    {
        try {
            $propertyMappingConfiguration = $this->arguments->getArgument('configuration')
                ->getPropertyMappingConfiguration();
            $propertyMappingConfiguration->allowProperties('workspace');
            $propertyMappingConfiguration->allowProperties('generateEmptyFocusKeywords');
            $propertyMappingConfiguration->allowProperties('regenerateExistingFocusKeywords');
            $propertyMappingConfiguration->allowProperties('nodeTypeFilter');
            $propertyMappingConfiguration->allowProperties('limit');
        } catch (NoSuchArgumentException) {
            // This cannot happen, otherwise we have a broken
            // request anyway
        }
    }

    /**
     * @param FocusKeywordModuleConfigurationDto $configuration
     *
     * @return string|bool
     */
    public function getFocusKeywordNodesAction(FocusKeywordModuleConfigurationDto $configuration): string|bool
    {
        $resultCollection = $this->nodeService->getNodesThatNeedProcessing($configuration, $this->controllerContext);
        return json_encode($resultCollection);
    }

    public function initializeUpdateFocusKeywordNodesAction(): void
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
     * @Flow\SkipCsrfProtection
     *
     * @param array<FocusKeywordModuleResultDto> $resultDtos
     *
     * @return string
     */
    public function updateFocusKeywordNodesAction(array $resultDtos): string
    {
        $this->nodeService->updateFocusKeywordOnNodes($resultDtos);
        return json_encode(array_map(fn (FocusKeywordModuleResultDto $item) => $item->jsonSerialize(), $resultDtos));
    }
}

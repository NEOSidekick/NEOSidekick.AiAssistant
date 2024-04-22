<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Property\PropertyMappingConfiguration;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;
use NEOSidekick\AiAssistant\Dto\AssetModuleResultDto;
use NEOSidekick\AiAssistant\Dto\FocusKeywordFilters;
use NEOSidekick\AiAssistant\Dto\FocusKeywordUpdateItem;
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

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    public function initializeIndexAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'propertyName',
                'onlyAssetsInUse',
                'firstResult',
                'limit',
                'language');
    }

    /**
     * @param AssetModuleConfigurationDto $configuration
     *
     * @return string
     */
    public function indexAction(AssetModuleConfigurationDto $configuration): string
    {
        $resultCollection = $this->assetService->getAssetsThatNeedProcessing($configuration);
        return json_encode($resultCollection);
    }

    public function initializeUpdateAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('resultDtos')
            ->getPropertyMappingConfiguration()
            ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
            ->skipUnknownProperties()
            ->allowAllProperties();
    }

    /**
     * @param array<AssetModuleResultDto> $resultDtos
     *
     * @return string
     */
    public function updateAction(array $resultDtos): string
    {
        $this->assetService->updateMultipleAssets($resultDtos);
        return json_encode(array_map(fn (AssetModuleResultDto $item) => $item->jsonSerialize(), $resultDtos));
    }

    public function initializeGetFocusKeywordNodesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'workspace',
                'generateEmptyFocusKeywords',
                'regenerateExistingFocusKeywords',
                'nodeTypeFilter',
                'firstResult',
                'limit'
            );
    }

    /**
     * @param FocusKeywordFilters $configuration
     *
     * @return string|bool
     */
    public function getFocusKeywordNodesAction(FocusKeywordFilters $configuration): string|bool
    {
        $resultCollection = $this->nodeService->find($configuration, $this->controllerContext);
        return json_encode($resultCollection);
    }

    public function initializeUpdateFocusKeywordNodesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('updateItems')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
            ->allowAllProperties();
    }

    /**
     * @Flow\SkipCsrfProtection
     *
     * @param array<FocusKeywordUpdateItem> $updateItems
     *
     * @return string
     */
    public function updateFocusKeywordNodesAction(array $updateItems): string
    {
        $this->nodeService->updatePropertiesOnNodes($updateItems);
        return json_encode(array_map(fn (FocusKeywordUpdateItem $item) => $item->jsonSerialize(), $updateItems));
    }
}

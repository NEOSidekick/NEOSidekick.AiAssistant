<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Property\PropertyMappingConfiguration;
use NEOSidekick\AiAssistant\Dto\FindAssetsFilterDto;
use NEOSidekick\AiAssistant\Dto\UpdateAssetData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Service\AssetService;
use NEOSidekick\AiAssistant\Service\NodeService;

/**
 * @noinspection PhpUnused
 */
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

    public function initializeFindAssetsAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'onlyAssetsInUse',
                'propertyNameMustBeEmpty',
                'firstResult',
                'limit'
                );
    }

    /**
     * @param FindAssetsFilterDto $configuration
     *
     * @return string
     */
    public function findAssetsAction(FindAssetsFilterDto $configuration): string
    {
        $resultCollection = $this->assetService->findImages($configuration);
        return json_encode($resultCollection);
    }

    public function initializeUpdateAssetsAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('updateItems')
            ->getPropertyMappingConfiguration()
            ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
            ->skipUnknownProperties()
            ->allowProperties(
                'identifier',
                'properties'
            );
    }

    /**
     * @param array<UpdateAssetData> $updateItems
     *
     * @return string
     */
    public function updateAssetsAction(array $updateItems): string
    {
        $this->assetService->updateMultipleAssets($updateItems);
        return json_encode(array_map(fn (UpdateAssetData $item) => $item->jsonSerialize(), $updateItems));
    }

    public function initializeFindDocumentNodesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'workspace',
                'propertyFilter',
                'nodeTypeFilter'
            );
    }

    /**
     * @param FindDocumentNodesFilter $configuration
     *
     * @return string|bool
     */
    public function findDocumentNodesAction(FindDocumentNodesFilter $configuration): string|bool
    {
        $resultCollection = $this->nodeService->find($configuration, $this->controllerContext);
        return json_encode($resultCollection);
    }

    public function initializeUpdateNodePropertiesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('updateItems')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
            ->allowProperties(
                'nodeContextPath',
                'properties'
            );
    }

    /**
     * @Flow\SkipCsrfProtection
     *
     * @param array<UpdateNodeProperties> $updateItems
     *
     * @return string
     */
    public function updateNodePropertiesAction(array $updateItems): string
    {
        $this->nodeService->updatePropertiesOnNodes($updateItems);
        return json_encode(array_map(fn (UpdateNodeProperties $item) => $item->jsonSerialize(), $updateItems));
    }
}

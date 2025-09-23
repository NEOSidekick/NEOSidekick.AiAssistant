<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use JsonException;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Property\PropertyMappingConfiguration;
use Neos\Neos\Routing\Exception\NoSiteException;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Dto\FindAssetsFilterDto;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateAssetData;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Exception\GetMostRelevantInternalSeoLinksApiException;
use Neos\Media\Domain\Model\ImageInterface;
use Neos\Media\Domain\Model\ImageVariant;
use NEOSidekick\AiAssistant\Service\AssetService;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Service\NodeWithImageService;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

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
     * @Flow\Inject
     * @var NodeWithImageService
     */
    protected $contentNodeService;

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * Allowed property names for asset updates
     *
     * @var string[]
     */
    private array $allowedAssetPropertyUpdates = ['title', 'caption'];

    /**
     * Allowed property names for node updates (applies to all node types)
     *
     * @var string[]
     */
    private array $allowedNodePropertyUpdates = ['titleOverride', 'metaDescription', 'focusKeyword'];

    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    protected function ensureJsonRequestOrReturn415(): ?string
    {
        $contentType = $this->request->getHttpRequest()->getHeaderLine('Content-Type');
        if (stripos($contentType, 'application/json') !== 0) {
            $this->response->setStatusCode(415);
            return json_encode(['error' => 'Unsupported Media Type: expected application/json'], JSON_THROW_ON_ERROR);
        }
        return null;
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
     * @throws JsonException
     */
    public function findAssetsAction(FindAssetsFilterDto $configuration): string
    {
        $resultCollection = $this->assetService->findImages($configuration, $this->controllerContext);
        return json_encode($resultCollection, JSON_THROW_ON_ERROR);
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
     * @throws JsonException
     */
    public function updateAssetsAction(array $updateItems): string
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return json_encode(['error' => 'Method Not Allowed'], JSON_THROW_ON_ERROR);
        }
        if (($error = $this->ensureJsonRequestOrReturn415()) !== null) {
            return $error;
        }
        // Filter properties against allowlist before updating
        $filteredItems = array_map(function (UpdateAssetData $item): UpdateAssetData {
            $filteredProperties = array_intersect_key(
                $item->getProperties(),
                array_flip($this->allowedAssetPropertyUpdates)
            );
            return new UpdateAssetData($item->getIdentifier(), $filteredProperties);
        }, $updateItems);

        $this->assetService->updateMultipleAssets($filteredItems);
        return json_encode(array_map(static fn(UpdateAssetData $item) => $item->jsonSerialize(), $filteredItems),
            JSON_THROW_ON_ERROR);
    }

    public function initializeFindDocumentNodesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'filter',
                'workspace',
                'seoPropertiesFilter',
                'imagePropertiesFilter',
                'focusKeywordPropertyFilter',
                'languageDimensionFilter',
                'nodeTypeFilter'
            );
    }

    /**
     * @param FindDocumentNodesFilter $configuration
     *
     * @return string|bool
     * @throws ClientExceptionInterface
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws JsonException
     * @throws MissingActionNameException
     * @throws NoSiteException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function findDocumentNodesAction(FindDocumentNodesFilter $configuration): string|bool
    {
        if ($configuration->getFilter() === 'important-pages') {
            try {
                $resultCollection = $this->nodeService->findImportantPages($configuration, $this->controllerContext, $this->userService->getInterfaceLanguage());
            } catch (GetMostRelevantInternalSeoLinksApiException $e) {
                return $this->handleException($e);
            }
        } else {
            $resultCollection = $this->nodeService->find($configuration, $this->controllerContext);
        }
        return json_encode($resultCollection, JSON_THROW_ON_ERROR);
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
                'properties',
                'images'
            );
    }

    /**
     * @Flow\SkipCsrfProtection
     *
     * @param array<UpdateNodeProperties> $updateItems
     *
     * @return string
     * @throws JsonException
     */
    public function updateNodePropertiesAction(array $updateItems): string
    {
        if ($this->request->getHttpRequest()->getMethod() !== 'POST') {
            $this->response->setStatusCode(405);
            return json_encode(['error' => 'Method Not Allowed'], JSON_THROW_ON_ERROR);
        }
        if (($error = $this->ensureJsonRequestOrReturn415()) !== null) {
            return $error;
        }
        // Filter node properties against allowlist before updating
        $filteredItems = array_map(function (UpdateNodeProperties $item): UpdateNodeProperties {
            $filteredProperties = array_intersect_key(
                $item->getProperties(),
                array_flip($this->allowedNodePropertyUpdates)
            );
            return new UpdateNodeProperties($item->getNodeContextPath(), $filteredProperties, $item->getImages());
        }, $updateItems);

        $this->nodeService->updatePropertiesOnNodes($filteredItems);
        return json_encode(array_map(static fn(UpdateNodeProperties $item) => $item->jsonSerialize(), $filteredItems),
            JSON_THROW_ON_ERROR);
    }

    public function initializeFindDocumentNodesWithImagesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('configuration')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->allowProperties(
                'filter',
                'workspace',
                'seoPropertiesFilter',
                'imagePropertiesFilter',
                'focusKeywordPropertyFilter',
                'languageDimensionFilter',
                'nodeTypeFilter'
            );
    }

    /**
     * @param FindDocumentNodesFilter $configuration
     *
     * @return string
     * @throws ClientExceptionInterface
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws JsonException
     * @throws MissingActionNameException
     * @throws NoSiteException
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws \Neos\Neos\Exception
     */
    public function findDocumentNodesWithImagesAction(FindDocumentNodesFilter $configuration): string
    {
        if ($configuration->getFilter() === 'important-pages') {
            try {
                $resultCollection = $this->nodeService->findImportantPages($configuration, $this->controllerContext, $this->userService->getInterfaceLanguage());
            } catch (GetMostRelevantInternalSeoLinksApiException $e) {
                return $this->handleException($e);
            }
        } else {
            $resultCollection = $this->nodeService->find($configuration, $this->controllerContext);
        }
        return json_encode($this->contentNodeService->findDocumentNodesHavingChildNodesWithImages($configuration, $resultCollection, $this->controllerContext), JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    protected function handleException(Throwable $exception): string
    {
        $this->response->setStatusCode(500);
        $this->response->setContentType('application/json');
        return json_encode(['error' => $exception->getMessage(), 'code' => $exception->getCode()], JSON_THROW_ON_ERROR);
    }

    /**
     * Fetch the metadata for a given image
     *
     * @param ImageInterface $image
     *
     * @return string JSON encoded response
     * @throws JsonException
     */
    public function imageTitleAndCaptionAction(ImageInterface $image): string
    {
        $this->response->setContentType('application/json');
        if ($image instanceof ImageVariant) {
            $originalImage = $image->getOriginalAsset();
        } else {
            $originalImage = $image;
        }
        // fallback to cleaned filename
        $resource = $image->getResource();
        $filename = str_replace('.' . $resource->getFileExtension(), '', $resource->getFilename());
        $filename = str_replace('_', ' ', $filename);
        $filename = strtoupper(substr($filename, 0, 1)) . substr($filename, 1);
        return json_encode([
            'title' => $originalImage->getTitle(),
            'caption' => $originalImage->getCaption(),
            'filenameCleaned' => $filename
        ], JSON_THROW_ON_ERROR);
    }
}

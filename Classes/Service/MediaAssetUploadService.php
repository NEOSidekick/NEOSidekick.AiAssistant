<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use Neos\Utility\MediaTypes;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Upload media assets to the Neos media library from remote URLs.
 *
 * Uses the same internal naming semantics as Neos.Media.Browser's uploadAction.
 *
 * @Flow\Scope("singleton")
 */
class MediaAssetUploadService
{
    /**
     * @Flow\Inject
     * @var Browser
     */
    protected $browser;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetModelMappingStrategyInterface
     */
    protected $assetModelMappingStrategy;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="resource.extensionsBlockedFromUpload")
     * @var array<string, bool>|null
     */
    protected $extensionsBlockedFromUpload;

    /**
     * Upload an asset from a remote URL.
     *
     * @param string $sourceUrl
     * @param string|null $title Optional title to set on the asset
     * @param string|null $caption Optional caption to set on the asset
     * @return array<string, mixed>
     */
    public function uploadFromUrl(string $sourceUrl, ?string $title = null, ?string $caption = null): array
    {
        $sourceUrl = trim($sourceUrl);
        $this->assertSupportedSourceUrl($sourceUrl);

        try {
            $response = $this->browser->request($sourceUrl, 'GET');
        } catch (ClientExceptionInterface $exception) {
            throw new \RuntimeException('Downloading the source URL failed: ' . $exception->getMessage(), 502, $exception);
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(
                sprintf('Downloading the source URL failed with HTTP status %d.', $response->getStatusCode()),
                502
            );
        }

        // Flow checks blocked extensions on import, where a stream has no name yet: check before storing anything.
        $filename = $this->resolveFilename(
            $sourceUrl,
            $response->getHeaderLine('Content-Disposition'),
            $response->getHeaderLine('Content-Type')
        );
        $this->assertFilenameIsNotBlocked($filename);

        $responseBody = $response->getBody();
        $importStream = fopen('php://temp', 'wb+');
        if ($importStream === false) {
            throw new \RuntimeException('Could not create temporary stream for downloaded asset.', 500);
        }

        if ($responseBody->isSeekable()) {
            $responseBody->rewind();
        }
        while (!$responseBody->eof()) {
            fwrite($importStream, $responseBody->read(8192));
        }
        rewind($importStream);

        try {
            $resource = $this->resourceManager->importResource($importStream);
        } finally {
            if (is_resource($importStream)) {
                fclose($importStream);
            }
        }

        $resource->setFilename($filename);

        $existingAsset = $this->assetRepository->findOneByResourceSha1($resource->getSha1());
        $isNewAsset = !($existingAsset instanceof AssetInterface);

        if ($isNewAsset) {
            $assetModelClassName = $this->assetModelMappingStrategy->map($resource);
            $asset = new $assetModelClassName($resource);

            if (!$asset instanceof AssetInterface) {
                throw new \RuntimeException(sprintf(
                    'Mapped asset model "%s" is not a valid AssetInterface implementation.',
                    $assetModelClassName
                ));
            }
        } else {
            $asset = $existingAsset;
            $this->resourceManager->deleteResource($resource);
        }

        if ($title !== null) {
            $asset->setTitle($title);
        }
        if ($caption !== null) {
            $asset->setCaption($caption);
        }

        if ($isNewAsset) {
            $this->assetRepository->add($asset);
        } elseif ($title !== null || $caption !== null) {
            $this->assetRepository->update($asset);
        }

        $this->persistenceManager->persistAll();

        $assetResource = $asset->getResource();
        $previewUrl = $assetResource !== null ? ($this->resourceManager->getPublicPersistentResourceUri($assetResource) ?? '') : '';

        return [
            'created' => $isNewAsset,
            'sourceUrl' => $sourceUrl,
            'asset' => [
                'identifier' => $asset->getIdentifier(),
                'filename' => $assetResource !== null ? $assetResource->getFilename() : '',
                'title' => $asset->getTitle() ?? '',
                'caption' => $asset->getCaption() ?? '',
                'mediaType' => $asset->getMediaType(),
                'previewUrl' => $previewUrl,
            ]
        ];
    }

    /**
     * @param string $sourceUrl
     * @return void
     */
    protected function assertSupportedSourceUrl(string $sourceUrl): void
    {
        if ($sourceUrl === '' || filter_var($sourceUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The "url" field must be a valid URL.');
        }

        $scheme = strtolower((string)parse_url($sourceUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only "http" and "https" URLs are supported.');
        }
    }

    /**
     * Rejects a filename if any part after a dot is listed in Neos.Flow.resource.extensionsBlockedFromUpload,
     * e.g. "php" and "png" in "foo.php.png", "htaccess" in ".htaccess".
     *
     * @param string $filename
     * @return void
     */
    protected function assertFilenameIsNotBlocked(string $filename): void
    {
        $basename = basename(str_replace('\\', '/', rtrim($filename, ". \t\n\r\0\x0B")));
        foreach (array_slice(explode('.', $basename), 1) as $extension) {
            if (($this->extensionsBlockedFromUpload[strtolower($extension)] ?? false) === true) {
                throw new InvalidArgumentException(sprintf(
                    'Files with the extension "%s" cannot be uploaded.',
                    strip_tags($extension)
                ));
            }
        }
    }

    /**
     * @param string $sourceUrl
     * @param string $contentDispositionHeader
     * @param string $contentTypeHeader
     * @return string
     */
    protected function resolveFilename(
        string $sourceUrl,
        string $contentDispositionHeader,
        string $contentTypeHeader
    ): string {
        $filename = $this->parseContentDispositionFilename($contentDispositionHeader);

        if ($filename === null) {
            $path = parse_url($sourceUrl, PHP_URL_PATH);
            $filename = is_string($path) ? basename($path) : '';
            $filename = rawurldecode($filename);
        }

        $filename = str_replace('\\', '/', trim($filename));
        $filename = basename($filename);
        $filename = preg_replace('/[\x00-\x1F\x7F]/', '', $filename) ?? '';

        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'downloaded-asset';
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $contentTypeParts = explode(';', $contentTypeHeader, 2);
            $mediaType = trim($contentTypeParts[0] ?? '');
            $extension = $mediaType !== '' ? MediaTypes::getFilenameExtensionFromMediaType($mediaType) : '';
            if ($extension !== '') {
                $filename .= '.' . $extension;
            }
        }

        return $filename;
    }

    /**
     * @param string $contentDispositionHeader
     * @return string|null
     */
    protected function parseContentDispositionFilename(string $contentDispositionHeader): ?string
    {
        if ($contentDispositionHeader === '') {
            return null;
        }

        if (preg_match('/filename\\*=([^;]+)/i', $contentDispositionHeader, $matches) === 1) {
            $value = trim($matches[1], " \t\n\r\0\x0B\"'");
            if (str_contains($value, "''")) {
                [, $encodedFilename] = explode("''", $value, 2);
                return rawurldecode($encodedFilename);
            }
            return rawurldecode($value);
        }

        if (preg_match('/filename="?([^\";]+)"?/i', $contentDispositionHeader, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

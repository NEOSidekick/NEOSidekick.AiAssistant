<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\ResourceManagement\PersistentResource;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Strategy\AssetModelMappingStrategyInterface;
use NEOSidekick\AiAssistant\Service\MediaAssetUploadService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Flow checks blocked filename extensions when a resource is imported, but a stream has no name
 * at that point. The upload therefore has to refuse the remote filename itself, before the body
 * is stored: every part after a dot counts, case-insensitively, trailing dots and whitespace ignored.
 */
class MediaAssetUploadServiceTest extends TestCase
{
    private const BLOCKED_EXTENSIONS = [
        'htaccess' => true,
        'phar' => true,
        'php' => true,
        'pht' => true,
        'shtml' => true,
        'pdf' => false,
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function blockedFilenameProvider(): array
    {
        return [
            'x.php' => ['x.php'],
            'X.PHP' => ['X.PHP'],
            'x.php.' => ['x.php.'],
            'x.php. .' => ['x.php. .'],
            'x.php.png' => ['x.php.png'],
            'x.phar' => ['x.phar'],
            'x.pht' => ['x.pht'],
            '.htaccess' => ['.htaccess'],
            'x.shtml' => ['x.shtml'],
        ];
    }

    #[Test]
    #[DataProvider('blockedFilenameProvider')]
    public function aBlockedFilenameFromTheContentDispositionHeaderIsRefusedBeforeAnythingIsStored(string $filename): void
    {
        $service = $this->createServiceThatMustNotStoreAnything(new Response(
            200,
            ['Content-Disposition' => 'attachment; filename="' . $filename . '"', 'Content-Type' => 'image/png'],
            'body'
        ));

        $this->expectException(InvalidArgumentException::class);
        $service->uploadFromUrl('https://example.com/image.png');
    }

    #[Test]
    #[DataProvider('blockedFilenameProvider')]
    public function aBlockedFilenameFromTheUrlPathIsRefusedBeforeAnythingIsStored(string $filename): void
    {
        $service = $this->createServiceThatMustNotStoreAnything(new Response(200, ['Content-Type' => 'image/png'], 'body'));

        $this->expectException(InvalidArgumentException::class);
        $service->uploadFromUrl('https://example.com/files/' . rawurlencode($filename));
    }

    #[Test]
    public function anEncodedContentDispositionFilenameIsCheckedInItsDecodedForm(): void
    {
        $service = $this->createServiceThatMustNotStoreAnything(new Response(
            200,
            ['Content-Disposition' => "attachment; filename*=UTF-8''x%2Ephp"],
            'body'
        ));

        $this->expectException(InvalidArgumentException::class);
        $service->uploadFromUrl('https://example.com/image.png');
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function acceptedFilenameProvider(): array
    {
        return [
            'photo.jpg' => ['https://example.com/photo.jpg', 'image/jpeg', 'photo.jpg'],
            'doc.pdf, listed but not blocked' => ['https://example.com/doc.pdf', 'application/pdf', 'doc.pdf'],
            'extension from the media type' => ['https://example.com/download', 'image/png; charset=binary', 'download.png'],
        ];
    }

    #[Test]
    #[DataProvider('acceptedFilenameProvider')]
    public function anAllowedFilenameIsImportedAndNamed(string $sourceUrl, string $contentType, string $expectedFilename): void
    {
        $resource = $this->createMock(PersistentResource::class);
        $resource->expects(self::once())->method('setFilename')->with($expectedFilename);
        $resource->method('getSha1')->willReturn('da39a3ee5e6b4b0d3255bfef95601890afd80709');

        $resourceManager = $this->createMock(ResourceManager::class);
        $resourceManager->expects(self::once())->method('importResource')->willReturn($resource);

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->method('findOneByResourceSha1')->willReturn($this->createMock(Asset::class));

        $service = $this->createService(
            new Response(200, ['Content-Type' => $contentType], 'body'),
            $resourceManager,
            $assetRepository,
            $this->createMock(PersistenceManagerInterface::class)
        );

        $result = $service->uploadFromUrl($sourceUrl);

        self::assertFalse($result['created']);
    }

    private function createServiceThatMustNotStoreAnything(Response $response): MediaAssetUploadService
    {
        $resourceManager = $this->createMock(ResourceManager::class);
        $resourceManager->expects(self::never())->method('importResource');
        $resourceManager->expects(self::never())->method('importResourceFromContent');

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->expects(self::never())->method('add');

        $persistenceManager = $this->createMock(PersistenceManagerInterface::class);
        $persistenceManager->expects(self::never())->method('persistAll');

        return $this->createService($response, $resourceManager, $assetRepository, $persistenceManager);
    }

    /**
     * @param ResourceManager&MockObject $resourceManager
     * @param AssetRepository&MockObject $assetRepository
     * @param PersistenceManagerInterface&MockObject $persistenceManager
     */
    private function createService(
        Response $response,
        ResourceManager $resourceManager,
        AssetRepository $assetRepository,
        PersistenceManagerInterface $persistenceManager
    ): MediaAssetUploadService {
        $browser = $this->createMock(Browser::class);
        $browser->method('request')->willReturn($response);

        $service = new MediaAssetUploadService();
        foreach (
            [
                'browser' => $browser,
                'resourceManager' => $resourceManager,
                'assetRepository' => $assetRepository,
                'assetModelMappingStrategy' => $this->createMock(AssetModelMappingStrategyInterface::class),
                'persistenceManager' => $persistenceManager,
                'extensionsBlockedFromUpload' => self::BLOCKED_EXTENSIONS,
            ] as $propertyName => $value
        ) {
            $property = new ReflectionProperty(MediaAssetUploadService::class, $propertyName);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }

        return $service;
    }
}

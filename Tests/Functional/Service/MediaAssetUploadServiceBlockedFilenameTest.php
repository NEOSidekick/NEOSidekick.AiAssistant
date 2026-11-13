<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\ResourceManagement\ResourceRepository;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Utility\ObjectAccess;
use NEOSidekick\AiAssistant\Service\MediaAssetUploadService;
use NEOSidekick\AiAssistant\Tests\Functional\FlowTestNameCompatibility;
use PHPUnit\Framework\Attributes\Test;

/**
 * A remote file whose name carries a blocked extension must leave nothing behind: the name is
 * only known to the upload service, so Flow's own check on import cannot catch it.
 */
class MediaAssetUploadServiceBlockedFilenameTest extends FunctionalTestCase
{
    use FlowTestNameCompatibility;

    protected static $testablePersistenceEnabled = true;

    private MediaAssetUploadService $service;

    /** @var mixed */
    private $originalBrowser;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = $this->objectManager->get(MediaAssetUploadService::class);
        $this->originalBrowser = ObjectAccess::getProperty($this->service, 'browser', true);
    }

    public function tearDown(): void
    {
        ObjectAccess::setProperty($this->service, 'browser', $this->originalBrowser, true);
        parent::tearDown();
    }

    #[Test]
    public function aBlockedFilenameCreatesNeitherAResourceNorAnAsset(): void
    {
        $browser = $this->createMock(Browser::class);
        $browser->expects(self::once())->method('request')->willReturn(new Response(
            200,
            ['Content-Disposition' => 'attachment; filename="image.php.png"', 'Content-Type' => 'image/png'],
            '<?php echo 1;'
        ));
        ObjectAccess::setProperty($this->service, 'browser', $browser, true);

        try {
            $this->service->uploadFromUrl('https://example.com/image.png');
            self::fail('The blocked filename was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('"php"', $exception->getMessage());
        }

        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        self::assertSame(0, $this->objectManager->get(ResourceRepository::class)->countAll());
        self::assertSame(0, $this->objectManager->get(AssetRepository::class)->countAll());
    }
}

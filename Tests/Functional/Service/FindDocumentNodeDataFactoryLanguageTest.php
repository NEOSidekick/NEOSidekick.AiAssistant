<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\Flow\Configuration\ConfigurationManager;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The language a row is generated in. With a language coordinate it is the dimension the node is
 * served in; without one - an installation that uses no language dimension - it is the configured
 * defaultLanguage. That fallback used to be a hardcoded 'de' which ignored the setting.
 */
class FindDocumentNodeDataFactoryLanguageTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    protected function setUpContentInLive(): void
    {
        $this->createPageWithImageNodes($this->getNodeByPath('/sites/example'), 'node-wan-kenodi', 'Seite 1', ['image1.jpg']);
    }

    public function tearDown(): void
    {
        // The factory is a singleton: everything injected into it has to be put back.
        $configurationManager = $this->objectManager->get(ConfigurationManager::class);
        $factory = $this->objectManager->get(FindDocumentNodeDataFactory::class);
        $this->inject($factory, 'languageDimensionName', $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'NEOSidekick.AiAssistant.languageDimensionName'
        ));
        $this->inject($factory, 'defaultLanguage', $configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_SETTINGS,
            'NEOSidekick.AiAssistant.defaultLanguage'
        ));

        parent::tearDown();
    }

    /**
     * @return array<string, FindDocumentNodeData>
     */
    private function findRows(): array
    {
        return $this->objectManager->get(NodeService::class)->find(
            new FindDocumentNodesFilter('custom', 'live'),
            $this->createControllerContextForDomain('example.com')
        );
    }

    #[Test]
    public function itUsesTheConfiguredDefaultLanguageForNodesWithoutALanguageCoordinate(): void
    {
        $factory = $this->objectManager->get(FindDocumentNodeDataFactory::class);
        // A dimension the content repository does not define: its coordinate is null for every
        // node, exactly as on an installation without a language dimension.
        $this->inject($factory, 'languageDimensionName', 'nonExistingLanguageDimension');
        // Neither the old hardcoded 'de' nor the shipped 'en', so the setting is proven to be read.
        $this->inject($factory, 'defaultLanguage', 'it');

        $rows = $this->findRows();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('it', $row->getLanguage(), 'Without a language coordinate the configured defaultLanguage must apply');
        }
    }

    #[Test]
    public function itKeepsTheServedLanguageForNodesWithALanguageCoordinate(): void
    {
        $languageDimensionId = $this->languageDimensionId();
        if ($languageDimensionId === null) {
            $this->markTestSkipped('The hosting distribution defines no language dimension.');
        }
        $this->inject($this->objectManager->get(FindDocumentNodeDataFactory::class), 'defaultLanguage', 'it');

        $rows = $this->findRows();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $servedLanguage = NodeAddress::fromJsonString($row->getNodeContextPath())
                ->dimensionSpacePoint
                ->getCoordinate($languageDimensionId);
            $this->assertNotNull($servedLanguage);
            $this->assertSame($servedLanguage, $row->getLanguage(), 'defaultLanguage must not override a real language coordinate');
        }
    }
}

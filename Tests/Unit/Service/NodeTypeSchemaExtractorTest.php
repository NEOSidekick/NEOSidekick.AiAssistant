<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Service;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\I18n\Translator;
use NEOSidekick\AiAssistant\Service\NodeTypeSchemaExtractor;
use PHPUnit\Framework\TestCase;

class NodeTypeSchemaExtractorTest extends TestCase
{
    /**
     * A site can reset an inherited ui block with `ui: ''`; that must not take the whole schema down.
     *
     * @test
     */
    public function scalarUiConfigurationFallsBackToAnEmptyMap(): void
    {
        $nodeType = $this->createMock(NodeType::class);
        $nodeType->method('getName')->willReturn('Vendor.Site:Content.Text');
        $nodeType->method('getDeclaredSuperTypes')->willReturn([]);
        $nodeType->method('isOfType')->willReturn(false);
        $nodeType->method('getConfiguration')->willReturn(null);
        $nodeType->method('getProperties')->willReturn([
            'reset' => ['type' => 'string', 'ui' => ''],
            'text' => ['type' => 'string', 'ui' => ['label' => 'Text']],
        ]);

        $nodeTypeManager = $this->createMock(NodeTypeManager::class);
        $nodeTypeManager->method('getNodeTypes')->willReturn(['Vendor.Site:Content.Text' => $nodeType]);

        $extractor = new NodeTypeSchemaExtractor();
        $this->inject($extractor, 'nodeTypeManager', $nodeTypeManager);
        $this->inject($extractor, 'translator', $this->createMock(Translator::class));

        $properties = $extractor->extract()['nodeTypes'][0]['properties'];

        self::assertSame([], $properties['reset']['ui']);
        self::assertSame(['label' => 'Text'], $properties['text']['ui']);
    }

    private function inject(object $target, string $propertyName, object $dependency): void
    {
        $property = new \ReflectionProperty($target, $propertyName);
        $property->setAccessible(true);
        $property->setValue($target, $dependency);
    }
}

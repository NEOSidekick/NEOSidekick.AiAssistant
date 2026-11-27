<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * createVariant creates a page in the requested dimensions from the source dimensions, like
 * "Create empty" / "Create and copy" of the Neos dimension switcher.
 *
 * The pages exist in English only: `/page` with a text, and below it `/page/child` with a text.
 * `de` is an independent language, `en_UK` falls back to `en`.
 */
class NodePatchServiceCreateVariantTest extends FunctionalTestCase
{
    private const PAGE_PATH = '/sites/example/page';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const ENGLISH = ['language' => ['en']];
    private const GERMAN = ['language' => ['de']];
    private const BRITISH = ['language' => ['en_UK', 'en']];

    protected array $dimensions = ['en', 'en_UK'];
    protected array $siteHosts = ['example.com'];

    /**
     * @var array<string, string> node identifiers by name
     */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $englishContext = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => self::ENGLISH]);
        $englishSiteNode = $this->rootNode->getNode('/sites/example')->createVariantForContext($englishContext);

        $pageType = $this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:RestrictedPage');
        $textType = $this->nodeTypeManager->getNodeType(self::TEXT_NODE_TYPE);

        $page = $englishSiteNode->createNode('page', $pageType);
        $page->setProperty('title', 'Page');
        $pageText = $page->getNode('main')->createNode('text', $textType);
        $pageText->setProperty('text', 'english');
        $child = $page->createNode('child', $pageType);
        $child->setProperty('title', 'Child');
        $childText = $child->getNode('main')->createNode('text', $textType);
        $childText->setProperty('text', 'child english');

        $this->ids = [
            'page' => $page->getIdentifier(),
            'page-text' => $pageText->getIdentifier(),
            'child' => $child->getIdentifier(),
            'child-text' => $childText->getIdentifier(),
        ];

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function aPageIsCopiedIntoAnIndependentLanguageTogetherWithItsMissingParents(): void
    {
        $result = $this->createVariant('child', true, self::GERMAN, self::ENGLISH);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $row = json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['results'][0];
        $this->assertSame('createVariant', $row['operation']);
        $this->assertSame($this->ids['child'], $row['nodeId']);
        $this->assertSame([$this->ids['page']], $row['createdParentVariants'], 'The parent page was missing in German and is created too');

        $this->resetContentRepositoryState();
        foreach (['child' => null, 'child-text' => 'child english', 'page' => null, 'page-text' => 'english'] as $nodeName => $text) {
            $germanNode = $this->getNode($nodeName, self::GERMAN);
            $this->assertNotNull($germanNode, $nodeName . ' exists in German');
            $this->assertSame(self::GERMAN, $germanNode->getNodeData()->getDimensionValues());
            if ($text !== null) {
                $this->assertSame($text, $germanNode->getProperty('text'));
            }
        }
        $this->assertSame(self::ENGLISH, $this->getNode('child-text', self::ENGLISH)->getNodeData()->getDimensionValues());

        $update = $this->applyPatches(
            [['operation' => 'updateNode', 'nodeId' => $this->ids['child-text'], 'properties' => ['text' => 'Kind deutsch']]],
            self::GERMAN
        );
        $this->assertTrue($update->isSuccess(), json_encode($update));
        $this->resetContentRepositoryState();
        $this->assertSame('Kind deutsch', $this->getNode('child-text', self::GERMAN)->getProperty('text'));
        $this->assertSame('child english', $this->getNode('child-text', self::ENGLISH)->getProperty('text'));
    }

    /**
     * @test
     */
    public function anEmptyVariantHasItsCollectionsButNoContent(): void
    {
        $result = $this->createVariant('page', false, self::GERMAN, self::ENGLISH);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $this->resetContentRepositoryState();
        $germanPage = $this->getNode('page', self::GERMAN);
        $this->assertNotNull($germanPage);
        $this->assertNotNull($germanPage->getNode('main'), 'The auto-created collection exists');
        $this->assertNull($this->getNode('page-text', self::GERMAN), 'The content was not copied');
    }

    /**
     * @test
     */
    public function aPageShownThroughAFallbackGetsItsOwnVariantThatCanBeChanged(): void
    {
        $result = $this->createVariant('page', true, self::BRITISH, self::ENGLISH);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $this->resetContentRepositoryState();
        $this->assertSame(['language' => ['en_UK']], $this->getNode('page-text', self::BRITISH)->getNodeData()->getDimensionValues());

        $update = $this->applyPatches(
            [['operation' => 'updateNode', 'nodeId' => $this->ids['page-text'], 'properties' => ['text' => 'british']]],
            self::BRITISH
        );
        $this->assertTrue($update->isSuccess(), json_encode($update));
        $this->resetContentRepositoryState();
        $this->assertSame('british', $this->getNode('page-text', self::BRITISH)->getProperty('text'));
        $this->assertSame('english', $this->getNode('page-text', self::ENGLISH)->getProperty('text'));
    }

    /**
     * @test
     */
    public function aVariantThatAlreadyExistsIsNotCreatedAgain(): void
    {
        $this->assertTrue($this->createVariant('page', true, self::GERMAN, self::ENGLISH)->isSuccess());
        $this->resetContentRepositoryState();

        $result = $this->createVariant('page', true, self::GERMAN, self::ENGLISH);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('already has its own variant in the requested dimensions', $result->getError()->getMessage());
    }

    /**
     * @return array<string, array{string, array<string, array<int, string>>, string}>
     */
    public static function refusedVariants(): array
    {
        return [
            'a content element' => ['page-text', self::ENGLISH, 'is not a page'],
            'a page missing in the source dimensions' => ['page', self::GERMAN, 'does not exist in the source dimensions (language=de)'],
            'no source dimensions' => ['page', [], 'createVariant needs "sourceDimensions"'],
        ];
    }

    /**
     * @test
     * @dataProvider refusedVariants
     * @param array<string, array<int, string>> $sourceDimensions
     */
    public function invalidVariantsAreRefusedWithoutWriting(string $nodeName, array $sourceDimensions, string $expectedMessage): void
    {
        $result = $this->createVariant($nodeName, true, self::GERMAN, $sourceDimensions);

        $this->assertFalse($result->isSuccess(), json_encode($result));
        $this->assertStringContainsString($expectedMessage, $result->getError()->getMessage());
        $this->resetContentRepositoryState();
        $this->assertNull($this->getNode('page', self::GERMAN));
    }

    /**
     * @test
     */
    public function createVariantCannotBeCombinedWithOtherOperations(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'createVariant', 'nodeId' => $this->ids['page'], 'copyContent' => true],
            ['operation' => 'updateNode', 'nodeId' => $this->ids['page-text'], 'properties' => ['text' => 'deutsch']],
        ], self::GERMAN, self::ENGLISH);

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($result->isRollbackPerformed(), 'Refused before anything is written');
        $this->assertStringContainsString('createVariant cannot be combined with other operations', $result->getError()->getMessage());
    }

    /**
     * @param array<string, array<int, string>> $dimensions
     * @param array<string, array<int, string>> $sourceDimensions
     */
    private function createVariant(string $nodeName, bool $copyContent, array $dimensions, array $sourceDimensions): PatchResult
    {
        return $this->applyPatches(
            [['operation' => 'createVariant', 'nodeId' => $this->ids[$nodeName], 'copyContent' => $copyContent]],
            $dimensions,
            $sourceDimensions
        );
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     * @param array<string, array<int, string>> $dimensions
     * @param array<string, array<int, string>> $sourceDimensions
     */
    private function applyPatches(array $patches, array $dimensions, array $sourceDimensions = []): PatchResult
    {
        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            $dimensions,
            $sourceDimensions
        );
    }

    /**
     * @param array<string, array<int, string>> $dimensions
     */
    private function getNode(string $nodeName, array $dimensions): ?NodeInterface
    {
        return $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => $dimensions,
            'targetDimensions' => array_map(static fn(array $values): string => $values[0], $dimensions),
            'invisibleContentShown' => true,
        ])->getNodeByIdentifier($this->ids[$nodeName]);
    }

    private function resetContentRepositoryState(): void
    {
        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }
}

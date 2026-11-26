<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Patches applied in a preset with a fallback chain (en_UK -> en) must not reach into the fallback
 * dimension: the old CR materializes variants on update/reorder/delete of fallback nodes, moves
 * fallback children in their origin dimension, and lets a deleted variant's fallback content show
 * again after publishing. The batch is refused instead, naming every such node at once.
 *
 * Content of the page, as seen in en_UK:
 *
 * - `text-fallback`      exists in en only (shown through the fallback)
 * - `text-variant`       has its own en_UK variant next to the en node
 * - `text-uk-only`       exists in en_UK only
 * - `container-variant`  has its own en_UK variant; its child `text-inner` exists in en only
 */
class NodePatchServiceDimensionFallbackTest extends FunctionalTestCase
{
    private const PAGE_PATH = '/sites/example/patch-page';
    private const MAIN_PATH = self::PAGE_PATH . '/main';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';
    private const CONTAINER_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Container';
    private const UK_DIMENSIONS = ['language' => ['en_UK', 'en']];

    protected array $dimensions = ['en', 'en_UK'];
    protected array $siteHosts = ['example.com'];

    /**
     * @var array<string, string> node identifiers by node name
     */
    private array $ids = [];

    public function setUp(): void
    {
        parent::setUp();

        $englishContext = $this->contextFactory->create([
            'workspaceName' => 'live',
            'dimensions' => ['language' => ['en']],
        ]);
        $ukContext = $this->createUkContext('live');
        $englishSiteNode = $this->rootNode->getNode('/sites/example')->createVariantForContext($englishContext);

        $restrictedPageType = $this->nodeTypeManager->getNodeType('NEOSidekick.AiAssistant.Testing:RestrictedPage');
        $textType = $this->nodeTypeManager->getNodeType(self::TEXT_NODE_TYPE);
        $containerType = $this->nodeTypeManager->getNodeType(self::CONTAINER_NODE_TYPE);

        $main = $englishSiteNode->createNode('patch-page', $restrictedPageType)->getNode('main');
        $otherMain = $englishSiteNode->createNode('other-page', $restrictedPageType)->getNode('main');

        $textFallback = $main->createNode('text-fallback', $textType);
        $textFallback->setProperty('text', 'english only');
        $textVariant = $main->createNode('text-variant', $textType);
        $textVariant->setProperty('text', 'english');
        $containerVariant = $main->createNode('container-variant', $containerType);
        $textInner = $containerVariant->createNode('text-inner', $textType);
        $textInner->setProperty('text', 'english inner');

        $textVariant->createVariantForContext($ukContext)->setProperty('text', 'british');
        $containerVariant->createVariantForContext($ukContext);
        $textUkOnly = $ukContext->getNode(self::MAIN_PATH)->createNode('text-uk-only', $textType);

        $this->ids = [
            'main' => $main->getIdentifier(),
            'other-main' => $otherMain->getIdentifier(),
            'text-fallback' => $textFallback->getIdentifier(),
            'text-variant' => $textVariant->getIdentifier(),
            'text-uk-only' => $textUkOnly->getIdentifier(),
            'container-variant' => $containerVariant->getIdentifier(),
            'text-inner' => $textInner->getIdentifier(),
        ];

        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }

    /**
     * @test
     */
    public function fallbackNodesCannotBeUpdatedMovedOrDeletedAndAreNamedTogether(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'updateNode', 'nodeId' => $this->ids['text-variant'], 'properties' => ['text' => 'changed']],
            ['operation' => 'updateNode', 'nodeId' => $this->ids['text-fallback'], 'properties' => ['text' => 'changed']],
            ['operation' => 'moveNode', 'nodeId' => $this->ids['text-fallback'], 'targetNodeId' => $this->ids['text-variant'], 'position' => 'after'],
            ['operation' => 'deleteNode', 'nodeId' => $this->ids['text-inner']],
        ]);

        $this->assertFalse($result->isSuccess(), json_encode($result));
        $this->assertFalse($result->isRollbackPerformed(), 'Refused before anything is written');
        $this->assertSame(1, $result->getError()->getPatchIndex());
        $message = $result->getError()->getMessage();
        $this->assertStringContainsString(sprintf('"%s" is a dimension fallback shown from language=en', $this->ids['text-fallback']), $message);
        $this->assertStringContainsString(sprintf('"%s" is a dimension fallback shown from language=en', $this->ids['text-inner']), $message);
        $this->assertStringNotContainsString($this->ids['text-variant'], $message);

        $this->resetContentRepositoryState();
        $this->assertSame('british', $this->getUkNode('text-variant')->getProperty('text'));
        $this->assertSame(['language' => ['en']], $this->getUkNode('text-fallback')->getNodeData()->getDimensionValues(), 'No variant was materialized');
    }

    /**
     * @test
     */
    public function deletingAVariantThatAlsoExistsInTheFallbackIsRefusedAndHidingItWorks(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'deleteNode', 'nodeId' => $this->ids['text-variant']],
        ]);

        $this->assertFalse($result->isSuccess(), json_encode($result));
        $this->assertStringContainsString(
            sprintf('"%s" also exists in language=en: deleting it here would only remove this variant', $this->ids['text-variant']),
            $result->getError()->getMessage()
        );
        $this->assertStringContainsString('Hide it instead by setting "_hidden" to true', $result->getError()->getMessage());

        $result = $this->applyPatches([
            ['operation' => 'updateNode', 'nodeId' => $this->ids['text-variant'], 'properties' => ['_hidden' => true]],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $this->resetContentRepositoryState();
        $this->assertTrue($this->getUkNode('text-variant')->isHidden());
        $visitorUkContext = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => self::UK_DIMENSIONS,
            'targetDimensions' => ['language' => 'en_UK'],
            'invisibleContentShown' => false,
        ]);
        $this->assertNull(
            $visitorUkContext->getNodeByIdentifier($this->ids['text-variant']),
            'The hidden variant does not let the English content show through'
        );
        $englishNode = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => ['language' => ['en']],
        ])->getNodeByIdentifier($this->ids['text-variant']);
        $this->assertNotNull($englishNode, 'The English node stays visible');
        $this->assertSame('english', $englishNode->getProperty('text'));
    }

    /**
     * @test
     */
    public function hidingRequiresABooleanValue(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'updateNode', 'nodeId' => $this->ids['text-variant'], 'properties' => ['_hidden' => 'yes']],
        ]);

        $this->assertFalse($result->isSuccess(), json_encode($result));
        $this->assertSame('Property "_hidden" must be true or false.', $result->getError()->getMessage());
    }

    /**
     * @test
     */
    public function aNodeThatExistsOnlyInTheRequestedDimensionsCanBeDeleted(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'deleteNode', 'nodeId' => $this->ids['text-uk-only']],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $this->resetContentRepositoryState();
        $this->assertNull($this->createUkContext($this->currentUserWorkspace)->getNodeByIdentifier($this->ids['text-uk-only']));
    }

    /**
     * @test
     */
    public function movingAContainerWithFallbackChildrenToAnotherParentIsRefused(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'moveNode', 'nodeId' => $this->ids['container-variant'], 'targetNodeId' => $this->ids['other-main'], 'position' => 'into'],
        ]);

        $this->assertFalse($result->isSuccess(), json_encode($result));
        $this->assertStringContainsString(
            sprintf('"%s" contains dimension fallbacks such as "%s", shown from language=en', $this->ids['container-variant'], $this->ids['text-inner']),
            $result->getError()->getMessage()
        );
    }

    /**
     * @test
     */
    public function reorderingAContainerWithFallbackChildrenWithinItsParentLeavesTheFallbackContentInPlace(): void
    {
        $result = $this->applyPatches([
            ['operation' => 'moveNode', 'nodeId' => $this->ids['container-variant'], 'targetNodeId' => $this->ids['text-variant'], 'position' => 'before'],
        ]);

        $this->assertTrue($result->isSuccess(), json_encode($result));
        $this->resetContentRepositoryState();
        $textInner = $this->getUkNode('text-inner');
        $this->assertSame(self::MAIN_PATH . '/container-variant/text-inner', $textInner->getPath());
        $this->assertSame(['language' => ['en']], $textInner->getNodeData()->getDimensionValues());
    }

    /**
     * Why deleting such a variant is refused: publishing removes only the variant.
     *
     * @test
     */
    public function withoutTheGuardTheFallbackContentShowsAgainOnceADeletedVariantIsPublished(): void
    {
        $this->getUkNode('text-variant')->remove();
        $this->resetContentRepositoryState();
        $this->assertNull($this->getUkNode('text-variant'), 'Gone in the workspace');

        $removedVariant = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => self::UK_DIMENSIONS,
            'targetDimensions' => ['language' => 'en_UK'],
            'removedContentShown' => true,
        ])->getNodeByIdentifier($this->ids['text-variant']);
        $removedVariant->getWorkspace()->publishNode($removedVariant, $this->liveWorkspace);
        $this->resetContentRepositoryState();

        $nodeAfterPublishing = $this->createUkContext('live')->getNodeByIdentifier($this->ids['text-variant']);
        $this->assertNotNull($nodeAfterPublishing);
        $this->assertSame('english', $nodeAfterPublishing->getProperty('text'));
    }

    /**
     * Why such a move is refused: the fallback children are moved in the dimension they come from.
     *
     * @test
     */
    public function withoutTheGuardMovingAContainerAlsoMovesItsFallbackChildrenInTheirOwnDimension(): void
    {
        $this->getUkNode('container-variant')->moveInto($this->getUkNode('other-main'));
        $this->resetContentRepositoryState();

        $englishContext = $this->contextFactory->create([
            'workspaceName' => $this->currentUserWorkspace,
            'dimensions' => ['language' => ['en']],
        ]);
        $this->assertSame(
            '/sites/example/other-page/main/container-variant/text-inner',
            $englishContext->getNodeByIdentifier($this->ids['text-inner'])->getPath(),
            'The English text left its page'
        );
        $this->assertSame(
            self::MAIN_PATH . '/container-variant',
            $englishContext->getNodeByIdentifier($this->ids['container-variant'])->getPath(),
            'while its English container stayed'
        );
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     */
    private function applyPatches(array $patches): PatchResult
    {
        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            self::UK_DIMENSIONS
        );
    }

    private function getUkNode(string $nodeName): ?NodeInterface
    {
        return $this->createUkContext($this->currentUserWorkspace)->getNodeByIdentifier($this->ids[$nodeName]);
    }

    private function createUkContext(string $workspaceName): Context
    {
        return $this->contextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => self::UK_DIMENSIONS,
            'targetDimensions' => ['language' => 'en_UK'],
            'invisibleContentShown' => true,
        ]);
    }

    private function resetContentRepositoryState(): void
    {
        $this->saveNodesAndTearDownRootNodeAndRepository();
        $this->setUpRootNodeAndRepository();
    }
}

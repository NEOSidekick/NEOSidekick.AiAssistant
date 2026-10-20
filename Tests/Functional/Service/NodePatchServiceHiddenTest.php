<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * `_hidden` on updateNode sets the node's own disabled state. Neos 9 refuses to disable a disabled node and
 * to enable one that is not disabled, and runs the patch's property write before that: a refused state
 * change would leave the properties written and the rest of the batch unapplied.
 */
class NodePatchServiceHiddenTest extends FunctionalTestCase
{
    private const PAGE_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:Text';

    protected array $siteHosts = ['example.com'];

    private NodeAggregateId $visibleTextId;
    private NodeAggregateId $hiddenTextId;

    protected function setUpContentInLive(): void
    {
        $site = $this->getNodeByPath('/sites/example');
        $pageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $pageId,
            NodeTypeName::fromString(self::PAGE_NODE_TYPE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $site->aggregateId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Hidden Test', 'uriPathSegment' => 'hidden-test'])
        )->withNodeName(NodeName::fromString('hidden-test')));
        $mainId = $this->subgraph()->findNodeByPath(NodeName::fromString('main'), $pageId)->aggregateId;

        $this->visibleTextId = $this->createText($mainId, 'visible');
        $this->hiddenTextId = $this->createText($mainId, 'hidden');
        $this->disableNode($this->subgraph()->findNodeById($this->hiddenTextId), 'live');
    }

    #[Test]
    public function anUnchangedHiddenStateIsSkippedAndARepeatedOneAppliedOnce(): void
    {
        $language = $this->primaryLanguage();

        $result = $this->objectManager->get(NodePatchService::class)->applyPatches(
            [
                ['operation' => 'updateNode', 'nodeId' => $this->visibleTextId->value, 'properties' => ['_hidden' => false, 'text' => 'visible, edited']],
                ['operation' => 'updateNode', 'nodeId' => $this->hiddenTextId->value, 'properties' => ['_hidden' => true, 'text' => 'hidden, edited']],
                ['operation' => 'updateNode', 'nodeId' => $this->visibleTextId->value, 'properties' => ['_hidden' => true]],
                ['operation' => 'updateNode', 'nodeId' => $this->visibleTextId->value, 'properties' => ['_hidden' => true]],
            ],
            $this->currentUserWorkspace,
            $language === null ? [] : [$this->languageDimensionId()->value => [$language]]
        );

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));
        $subgraph = $this->subgraph($this->currentUserWorkspace);
        $formerlyVisibleText = $subgraph->findNodeById($this->visibleTextId);
        $hiddenText = $subgraph->findNodeById($this->hiddenTextId);
        $this->assertSame('visible, edited', $formerlyVisibleText->getProperty('text'));
        $this->assertTrue($formerlyVisibleText->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()));
        $this->assertSame('hidden, edited', $hiddenText->getProperty('text'));
        $this->assertTrue($hiddenText->tags->withoutInherited()->contain(NeosSubtreeTag::disabled()));
    }

    private function createText(NodeAggregateId $parentId, string $text): NodeAggregateId
    {
        $textId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $textId,
            NodeTypeName::fromString(self::TEXT_NODE_TYPE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $parentId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['text' => $text])
        ));

        return $textId;
    }
}

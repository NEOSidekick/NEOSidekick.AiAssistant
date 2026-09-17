<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use NEOSidekick\AiAssistant\Dto\Patch\CreatedNodeInfo;
use NEOSidekick\AiAssistant\Dto\Patch\PatchResult;
use NEOSidekick\AiAssistant\Service\NodePatchService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Flowpack.NodeTemplates is a creation-*dialog* handler: its templates evaluate the data an editor
 * entered in the dialog, which the apply-patches API never has. A createNode patch therefore creates
 * the requested node and its tethered child nodes only - no template properties, no template children.
 */
class NodePatchServiceNodeTemplatesTest extends FunctionalTestCase
{
    private const PAGE_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';
    private const TEMPLATED_TEXT_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:TemplatedText';
    private const TEMPLATED_CONTAINER_NODE_TYPE = 'NEOSidekick.AiAssistant.Testing:TemplatedContainer';

    protected array $siteHosts = ['example.com'];

    private NodeAggregateId $siteId;
    private NodeAggregateId $mainId;

    protected function setUpContentInLive(): void
    {
        $site = $this->getNodeByPath('/sites/example');
        $this->assertNotNull($site);
        $this->siteId = $site->aggregateId;

        $pageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $pageId,
            NodeTypeName::fromString(self::PAGE_NODE_TYPE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint()),
            $this->siteId,
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => 'Patch Test', 'uriPathSegment' => 'patch-test'])
        )->withNodeName(NodeName::fromString('patch-test')));

        $main = $this->subgraph()->findNodeByPath(NodeName::fromString('main'), $pageId);
        $this->assertNotNull($main, 'Tethered "main" child was not created');
        $this->mainId = $main->aggregateId;
    }

    /**
     * The regression guard for the data loss: a root `options.template.properties` entry reading
     * `${data.text}` must not overwrite the value the caller supplied in the same patch.
     */
    #[Test]
    public function callerPropertiesSurviveNodeTypeTemplates(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->mainId->value,
                'nodeType' => self::TEMPLATED_TEXT_NODE_TYPE,
                'position' => 'into',
                'properties' => ['text' => 'caller value'],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));

        $node = $this->subgraph($this->currentUserWorkspace)->findNodeById(NodeAggregateId::fromString($result->getResults()[0]['nodeId']));
        $this->assertNotNull($node);
        $this->assertSame(
            'caller value',
            $node->getProperty('text'),
            'The caller supplied "text"; the node type template must not null it.'
        );
    }

    /**
     * An unconditional template child must not be created: the caller asked for one node.
     */
    #[Test]
    public function createDoesNotAddNodeTemplateChildren(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->mainId->value,
                'nodeType' => self::TEMPLATED_CONTAINER_NODE_TYPE,
                'position' => 'into',
                'properties' => [],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));

        $containerId = NodeAggregateId::fromString($result->getResults()[0]['nodeId']);
        $this->assertCount(
            0,
            $this->subgraph($this->currentUserWorkspace)->findChildNodes($containerId, FindChildNodesFilter::create()),
            'No template child may be created'
        );
        $this->assertCount(
            1,
            $result->getResults()[0]['createdNodes'],
            'Only the requested node is reported as created'
        );
    }

    /**
     * The counterpart: tethered `childNodes:` are part of the node type, are still created and are
     * still reported in `createdNodes` at depth 1. Guards the fix against over-deletion (the `$ref/main`
     * anchor itself is covered by NodePatchServiceRefTest).
     */
    #[Test]
    public function tetheredChildNodesAreStillCreatedAndReported(): void
    {
        $result = $this->applyPatches([
            [
                'operation' => 'createNode',
                'positionRelativeToNodeId' => $this->siteId->value,
                'nodeType' => self::PAGE_NODE_TYPE,
                'position' => 'into',
                'properties' => ['title' => 'Second Page'],
            ],
        ]);

        $this->assertTrue($result->isSuccess(), 'Batch should succeed: ' . json_encode($result));

        /** @var CreatedNodeInfo[] $createdNodes */
        $createdNodes = $result->getResults()[0]['createdNodes'];
        $byDepthAndName = array_map(
            static fn(CreatedNodeInfo $node): string => $node->getDepth() . ':' . $node->getNodeName(),
            $createdNodes
        );
        $this->assertSame(self::PAGE_NODE_TYPE, $createdNodes[0]->getNodeType());
        $this->assertSame(0, $createdNodes[0]->getDepth());
        $this->assertContains('1:main', $byDepthAndName, 'The tethered main collection is reported at depth 1');
    }

    /**
     * @param array<int, array<string, mixed>> $patches
     */
    private function applyPatches(array $patches): PatchResult
    {
        $language = $this->primaryLanguage();

        return $this->objectManager->get(NodePatchService::class)->applyPatches(
            $patches,
            $this->currentUserWorkspace,
            $language === null ? [] : [$this->languageDimensionId()->value => [$language]]
        );
    }
}

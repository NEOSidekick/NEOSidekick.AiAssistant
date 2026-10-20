<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeCreation\Command\CreateNodeAggregateWithNode;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use NEOSidekick\AiAssistant\Dto\Patch\AbstractPatch;
use NEOSidekick\AiAssistant\Exception\PatchFailedException;
use NEOSidekick\AiAssistant\Service\PatchValidator;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pre-pass rules that only differ across dimensions: the content repository checks a node aggregate
 * in all its dimensions, while the batch is validated in one subgraph.
 *
 * Uses the distribution's language specialization pair (e.g. Neos.Demo: en_US → en_UK) next to the primary
 * language; skips when the hosting distribution configures none.
 */
class PatchValidatorDimensionsTest extends FunctionalTestCase
{
    private const PAGE = 'NEOSidekick.AiAssistant.Testing:RestrictedPage';

    protected array $siteHosts = ['example.com'];

    private ?string $generalization = null;
    private ?string $specialization = null;

    private string $siteId;
    private string $fallbackPageId;
    private string $translatedPageId;
    private string $twinId;
    private string $primaryOnlyClashId;
    private string $primaryOnlyTwinId;

    protected function setUpContentInLive(): void
    {
        [$this->generalization, $this->specialization] = $this->findSpecializationPair();
        if ($this->generalization === null || $this->generalization === $this->primaryLanguage()) {
            $this->markTestSkipped('This test needs a language specialization next to another top-level language.');
        }

        $site = $this->getNodeByPath('/sites/example');
        $this->siteId = $site->aggregateId->value;
        $this->createLanguageVariant($site, $this->generalization);

        // Exists only in the generalization; the specialization shows it as a fallback
        $this->fallbackPageId = $this->createPage($this->siteId, 'fallback', $this->generalization)->aggregateId->value;

        // Exists in the primary language and, through a variant, in the generalization and its specialization
        $translatedPage = $this->createPage($this->siteId, 'translated', $this->primaryLanguage());
        $this->translatedPageId = $translatedPage->aggregateId->value;
        $this->createLanguageVariant($translatedPage, $this->generalization);

        // Below the site the name "clash" is taken in the generalization only, "twin" in both languages
        $this->createPage($this->siteId, 'clash', $this->generalization);
        $twin = $this->createPage($this->siteId, 'twin', $this->primaryLanguage());
        $this->twinId = $twin->aggregateId->value;
        $this->createLanguageVariant($twin, $this->generalization);

        // Pages of those names elsewhere in the primary language, to be moved up to the site
        $folderId = $this->createPage($this->siteId, 'folder', $this->primaryLanguage())->aggregateId->value;
        $this->primaryOnlyClashId = $this->createPage($folderId, 'clash', $this->primaryLanguage())->aggregateId->value;
        $this->primaryOnlyTwinId = $this->createPage($folderId, 'twin', $this->primaryLanguage())->aggregateId->value;
    }

    #[Test]
    public function aFallbackNodeIsRefusedBeforeAnyPatchIsExecuted(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'updateNode', 'nodeId' => $this->fallbackPageId, 'properties' => ['title' => 'Changed']],
        ], $this->specialization);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame('updateNode', $exception->getOperation());
        self::assertSame(
            sprintf(
                'Node "%s" is a fallback of dimension %s in the requested dimension %s and cannot be edited. Create the variant in the Neos UI first.',
                $this->fallbackPageId,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint($this->generalization))->toJson(),
                $this->dimensionSpacePoint($this->specialization)->toJson()
            ),
            $exception->getMessage()
        );
    }

    /**
     * A move takes every variant along (STRATEGY_GATHER_ALL), so a page the batch creates in one language
     * cannot take a page that also exists in another.
     */
    #[Test]
    public function aMoveIsRejectedWhenTheNewParentDoesNotExistInEveryDimensionOfTheNode(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'createNode', 'positionRelativeToNodeId' => $this->siteId, 'nodeType' => self::PAGE, 'position' => 'into', 'ref' => 'section'],
            ['operation' => 'moveNode', 'nodeId' => $this->translatedPageId, 'targetNodeId' => '$section', 'position' => 'into'],
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame($this->translatedPageId, $exception->getNodeId());
        self::assertStringStartsWith(
            'Node "' . $this->translatedPageId . '" cannot be moved into "$section": a move takes every variant of the node along, and the target does not exist in ',
            $exception->getMessage()
        );
        self::assertStringContainsString($this->dimensionSpacePoint($this->generalization)->toJson(), $exception->getMessage());
        self::assertStringContainsString($this->dimensionSpacePoint($this->specialization)->toJson(), $exception->getMessage());
    }

    /**
     * Node names are unique among the children of a node aggregate in all dimensions
     * (requireNodeNameToBeUncovered() asks the content graph, not a subgraph).
     */
    #[Test]
    public function aMoveIsRejectedWhenTheNameIsTakenInAnotherDimension(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'moveNode', 'nodeId' => $this->primaryOnlyClashId, 'targetNodeId' => $this->siteId, 'position' => 'into'],
        ]);

        self::assertSame(0, $exception->getPatchIndex());
        self::assertSame(
            'Node "' . $this->primaryOnlyClashId . '" cannot be moved into node "' . $this->siteId . '": it already has a child node named "clash", and node names are unique among siblings.',
            $exception->getMessage()
        );
    }

    /**
     * deleteNode removes the requested dimension and its specializations; the variant in the other language
     * keeps the name taken.
     */
    #[Test]
    public function aSiblingDeletedInOneLanguageKeepsItsNameTaken(): void
    {
        $exception = $this->expectFailure([
            ['operation' => 'deleteNode', 'nodeId' => $this->twinId],
            ['operation' => 'moveNode', 'nodeId' => $this->primaryOnlyTwinId, 'targetNodeId' => $this->siteId, 'position' => 'into'],
        ]);

        self::assertSame(1, $exception->getPatchIndex());
        self::assertSame(
            'Node "' . $this->primaryOnlyTwinId . '" cannot be moved into node "' . $this->siteId . '": it already has a child node named "twin", and node names are unique among siblings.',
            $exception->getMessage()
        );
    }

    private function createPage(string $parentId, string $name, string $language): Node
    {
        $pageId = NodeAggregateId::create();
        $this->contentRepository->handle(CreateNodeAggregateWithNode::create(
            WorkspaceName::forLive(),
            $pageId,
            NodeTypeName::fromString(self::PAGE),
            OriginDimensionSpacePoint::fromDimensionSpacePoint($this->dimensionSpacePoint($language)),
            NodeAggregateId::fromString($parentId),
            initialPropertyValues: PropertyValuesToWrite::fromArray(['title' => $name, 'uriPathSegment' => $name])
        )->withNodeName(NodeName::fromString($name)));

        return $this->subgraph('live', $language)->findNodeById($pageId);
    }

    /**
     * @return array{0: ?string, 1: ?string} [generalization, specialization] language values
     */
    private function findSpecializationPair(): array
    {
        $dimensionId = $this->languageDimensionId();
        if ($dimensionId === null) {
            return [null, null];
        }
        $dimension = $this->contentRepository->getContentDimensionSource()->getDimension($dimensionId);
        foreach ($dimension->values as $value) {
            $generalization = $dimension->getGeneralization($value);
            if ($generalization !== null && $generalization->specializationDepth->value === 0) {
                return [$generalization->value, $value->value];
            }
        }

        return [null, null];
    }

    /**
     * @param array<int, array<string, mixed>> $patchesData
     */
    private function expectFailure(array $patchesData, ?string $language = null): PatchFailedException
    {
        $patches = array_map(static fn(array $data): AbstractPatch => AbstractPatch::fromArray($data), $patchesData);
        try {
            $this->objectManager->get(PatchValidator::class)->validatePatches(
                $patches,
                $this->contentRepository->getContentSubgraph(WorkspaceName::fromString($this->currentUserWorkspace), $this->dimensionSpacePoint($language))
            );
        } catch (PatchFailedException $exception) {
            return $exception;
        }
        self::fail('Expected the batch to be refused');
    }
}

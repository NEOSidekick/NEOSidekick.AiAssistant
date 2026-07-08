<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

abstract class AbstractNodeService
{
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Enumerates nodes matching the given node type names below the given site (or all sites) in the given
     * workspace, across all dimension space points of the content repository.
     *
     * This replaces the old NodeData Doctrine machinery (createQueryBuilder(),
     * addDimensionJoinConstraintsToQueryBuilder(), reduceNodeVariantsByWorkspaces()):
     * - the content graph of the requested workspace already contains the nodes inherited from its base
     *   workspaces, so no manual workspace-chain reduction is needed anymore;
     * - NodeVisibility::excludeDisabledAndRemoved() excludes disabled ("hidden") and soft-removed nodes, replacing the
     *   old "n.hidden = false AND n.removed = false" constraints.
     *
     * The result contains one entry per (nodeAggregateId, originDimensionSpacePoint), mirroring the old
     * one-row-per-NodeData semantics: a variant that is merely visible in other dimension space points
     * through fallbacks is not duplicated.
     *
     * @param array<string> $nodeTypeNames allowed node type names (already expanded to include subtypes)
     *
     * @return array<string, Node> keyed by "<nodeAggregateId>|<originDimensionSpacePointHash>"
     */
    protected function findDocumentNodesInWorkspace(
        ContentRepository $contentRepository,
        WorkspaceName $workspaceName,
        array $nodeTypeNames,
        ?string $siteNodeName = null
    ): array {
        if ($nodeTypeNames === []) {
            return [];
        }

        $nodesByVariant = [];
        foreach ($contentRepository->getVariationGraph()->getDimensionSpacePoints() as $dimensionSpacePoint) {
            $subgraph = $contentRepository->getContentGraph($workspaceName)
                ->getSubgraph($dimensionSpacePoint, NodeVisibility::excludeDisabledAndRemoved());
            $sitesRootNode = $subgraph->findRootNodeByType(NodeTypeName::fromString('Neos.Neos:Sites'));
            if ($sitesRootNode === null) {
                continue;
            }
            $entryNode = $sitesRootNode;
            if ($siteNodeName !== null) {
                $entryNode = $subgraph->findNodeByPath(NodeName::fromString($siteNodeName), $sitesRootNode->aggregateId);
                if ($entryNode === null) {
                    continue;
                }
            }

            $candidateNodes = [];
            // The old query matched the site node itself as well ("n.path = :currentSitePath OR LIKE ...")
            if ($entryNode !== $sitesRootNode && in_array($entryNode->nodeTypeName->value, $nodeTypeNames, true)) {
                $candidateNodes[] = $entryNode;
            }
            foreach ($subgraph->findDescendantNodes(
                $entryNode->aggregateId,
                FindDescendantNodesFilter::create(nodeTypes: implode(',', $nodeTypeNames))
            ) as $descendantNode) {
                $candidateNodes[] = $descendantNode;
            }

            foreach ($candidateNodes as $node) {
                $variantKey = $node->aggregateId->value . '|' . $node->originDimensionSpacePoint->hash;
                // Prefer the variant accessed in its origin dimension space point over fallback appearances
                if (!isset($nodesByVariant[$variantKey]) || $node->originDimensionSpacePoint->hash === $dimensionSpacePoint->hash) {
                    $nodesByVariant[$variantKey] = $node;
                }
            }
        }

        return $nodesByVariant;
    }

    protected function isNodeHidden(Node $node): bool
    {
        // NodeTags contains inherited subtree tags, so this also covers the old recursive parent lookup
        return $node->tags->contain(NeosSubtreeTag::disabled());
    }
}

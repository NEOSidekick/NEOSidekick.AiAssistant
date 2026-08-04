<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Detects whether a node is served as a dimension FALLBACK in its context: the context's
 * target dimension value is not among the values its underlying NodeData was created for
 * (e.g. a /uk page transparently serving the en content through the en_UK → en fallback).
 *
 * Fallback nodes are not editable rows in this package: writing to them would either change
 * the origin language's content or - via the old CR's implicit copy-on-write - materialize
 * a variant as a save side effect, permanently detaching the page from its fallback chain.
 * Variant creation must remain an explicit editor decision in the Neos UI.
 */
final class DimensionFallbackDetector
{
    private function __construct()
    {
    }

    public static function isDimensionFallback(NodeInterface $node): bool
    {
        $nodeDataDimensionValues = $node->getNodeData()->getDimensionValues();
        if ($nodeDataDimensionValues === []) {
            // dimension-less node data is visible in every dimension by design
            return false;
        }
        foreach ($node->getContext()->getTargetDimensions() as $dimensionName => $targetValue) {
            if (!in_array($targetValue, $nodeDataDimensionValues[$dimensionName] ?? [], true)) {
                return true;
            }
        }

        return false;
    }
}

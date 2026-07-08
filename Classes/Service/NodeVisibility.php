<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;

/**
 * Central mapping to Neos 9 visibility constraints. The core VisibilityConstraints::default() /
 * withoutRestrictions() factories are deprecated and will be removed with Neos 10, so all
 * subgraph queries of this package go through these two intent-revealing helpers instead.
 */
final class NodeVisibility
{
    private function __construct()
    {
    }

    /**
     * Frontend-like reading: neither disabled ("hidden") nor soft-removed nodes are visible.
     * Equivalent of the old CR's default context (hidden = false, removed = false).
     */
    public static function excludeDisabledAndRemoved(): VisibilityConstraints
    {
        return NeosVisibilityConstraints::excludeRemoved()
            ->merge(NeosVisibilityConstraints::excludeDisabled());
    }

    /**
     * Backend-like reading: disabled nodes are visible, soft-removed nodes are not.
     * Equivalent of the old CR's invisibleContentShown = true context.
     */
    public static function excludeRemoved(): VisibilityConstraints
    {
        return NeosVisibilityConstraints::excludeRemoved();
    }
}

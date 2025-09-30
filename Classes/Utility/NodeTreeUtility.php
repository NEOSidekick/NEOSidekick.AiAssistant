<?php

namespace NEOSidekick\AiAssistant\Utility;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Utility class for node tree operations
 */
class NodeTreeUtility
{
    /**
     * Get the identifier of the closest document node in the node tree
     *
     * @param NodeInterface $node The node to start from
     * @return string|null The identifier of the closest document node, or null if none found
     */
    public static function getClosestDocumentNodeIdentifier(NodeInterface $node): ?string
    {
        $closestDocumentNodeIdentifier = null;
        $closestDocumentNode = $node;
        do {
            if ($closestDocumentNode->getNodeType()->isAggregate()) {
                $closestDocumentNodeIdentifier = $closestDocumentNode->getIdentifier();
                break;
            }

            $closestDocumentNode = $closestDocumentNode->getParent();
        } while ($closestDocumentNode !== null);

        return $closestDocumentNodeIdentifier;
    }
}

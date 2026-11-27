<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service\Traits;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Reads the timestamps Neos stores for a node.
 *
 * They are fields of the NodeData record, not node properties, so neither `getProperty()` nor
 * `getProperties()` returns them. The values are passed on as stored:
 *
 * - `creationDateTime`: a dimension variant keeps the value of the node it was created from
 * - `lastModificationDateTime`: changes with this node only, not with the nodes below it
 * - `lastPublicationDateTime`: null as long as this record was never the target of a publication,
 *   which includes the workspace record of a published node with unpublished changes
 */
trait NodeTimestampExtractionTrait
{
    /**
     * @return array{creationDateTime: string, lastModificationDateTime: string, lastPublicationDateTime: string|null}
     */
    protected function extractTimestamps(NodeInterface $node): array
    {
        $nodeData = $node->getNodeData();

        return [
            'creationDateTime' => $nodeData->getCreationDateTime()->format(\DateTimeInterface::ATOM),
            'lastModificationDateTime' => $nodeData->getLastModificationDateTime()->format(\DateTimeInterface::ATOM),
            'lastPublicationDateTime' => $nodeData->getLastPublicationDateTime()?->format(\DateTimeInterface::ATOM),
        ];
    }
}

<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Psr\Log\LoggerInterface;

/**
 * Service for fetching node data from content contexts
 *
 * @Flow\Scope("singleton")
 */
class NodeDataService
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * Find a node in a specific workspace with given dimensions
     *
     * @param string $nodeIdentifier The identifier of the node to find
     * @param string $workspaceName The name of the workspace to search in
     * @param array $dimensions The dimensions to use
     * @return NodeInterface|null The node if found, null otherwise
     */
    public function findNodeInWorkspace(string $nodeIdentifier, string $workspaceName, array $dimensions = []): ?NodeInterface
    {
        try {
            $context = $this->createContentContext($workspaceName, $dimensions);
            return $context->getNodeByIdentifier($nodeIdentifier);
        } catch (\Exception $e) {
            $this->systemLogger->warning('Could not fetch node from workspace: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'nodeIdentifier' => $nodeIdentifier,
                'workspaceName' => $workspaceName
            ]);
            return null;
        }
    }

    /**
     * Render a node as an array with its properties
     *
     * @param NodeInterface $node The node to render
     * @param bool $includeProperties Whether to include the node's properties
     * @return array The node as an array
     */
    public function renderNodeArray(NodeInterface $node, bool $includeProperties = false): array
    {
        $nodeArray = [
            'identifier' => $node->getIdentifier(),
            'name' => $node->getName(),
            'path' => $node->getPath(),
            'type' => $node->getNodeType()->getName(),
            'workspace' => $node->getWorkspace()->getName(),
            'dimensions' => $node->getDimensions()
        ];

        if ($includeProperties) {
            $nodeArray['properties'] = (array) $node->getProperties();
        }

        return $nodeArray;
    }
}

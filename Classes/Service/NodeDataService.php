<?php

namespace NEOSidekick\AiAssistant\Service;

use Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use NEOSidekick\AiAssistant\Dto\NodeDataDto;
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
        } catch (Exception $e) {
            $this->systemLogger->warning('Could not fetch node from workspace: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'nodeIdentifier' => $nodeIdentifier,
                'workspaceName' => $workspaceName
            ]);
            return null;
        }
    }

    /**
     * Create a NodeDataDto from a node. Only string properties will be included.
     *
     * @param NodeInterface $node The node to convert to a DTO
     * @param bool $includeProperties Whether to include the node's string properties
     * @return NodeDataDto The node as a DTO
     */
    public function createNodeDataDto(NodeInterface $node, bool $includeProperties = false): NodeDataDto
    {
        $properties = null;

        if ($includeProperties) {
            $stringProperties = [];
            $allProperties = $node->getProperties();
            foreach ($allProperties as $propertyName => $propertyValue) {
                if (is_string($propertyValue)) {
                    $stringProperties[$propertyName] = $propertyValue;
                }
            }
            $properties = $stringProperties;
        }

        return new NodeDataDto(
            $node->getIdentifier(),
            $node->getPath(),
            $node->getWorkspace()->getName(),
            $node->getDimensions(),
            $node->getName(),
            $properties
        );
    }
}

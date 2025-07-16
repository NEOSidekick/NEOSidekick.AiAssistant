<?php

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequestFactory;
use Neos\Flow\Mvc\ActionResponse;
use Neos\Flow\Mvc\Controller\Arguments;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Controller\CreateContentContextTrait;
use NEOSidekick\AiAssistant\Domain\Service\AutomationsConfigurationService;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use Psr\Log\LoggerInterface;
use NEOSidekick\AiAssistant\Dto\NodeChangeDto;
use NEOSidekick\AiAssistant\Dto\WorkspacePublishedDto;

/**
 * @Flow\Scope("singleton")
 */
class SignalCollectionService
{
    use CreateContentContextTrait;

    /**
     * @var LoggerInterface
     * @Flow\Inject
     */
    protected $systemLogger;

    /**
     * Array to store "before" and "after" states during publishing signals.
     * Example:
     * [
     *   'some-node-identifier' => [
     *     'before' => [
     *       'identifier' => '...',
     *       'name' => '...',
     *       'properties' => [ ... ]
     *     ],
     *     'after' => [
     *       'identifier' => '...',
     *       'name' => '...',
     *       'properties' => [ ... ]
     *     ]
     *   ],
     *   ...
     * ]
     */
    protected array $nodePublishingData = [];
    protected ?string $nodePublishingWorkspaceName = null;
    protected AutomationsConfigurationService $automationsConfigurationService;
    protected ApiFacade $apiFacade;

    public function injectAutomationsConfigurationService(AutomationsConfigurationService $automationsConfigurationService): void
    {
        $this->automationsConfigurationService = $automationsConfigurationService;
    }

    public function injectApiFacade(ApiFacade $apiFacade): void
    {
        $this->apiFacade = $apiFacade;
    }

    /**
     * Registers various signals. Sends immediate webhooks for node add/remove/etc.
     */
    public function registerSignal(mixed ...$args): void
    {
        // The last array element is always the signal class + method string:
        $signalClassAndMethod = array_pop($args);

        switch ($signalClassAndMethod) {
            // ----------------------------------------------------------------
            // Accumulate before/after states for final "WorkspacePublished" event
            // ----------------------------------------------------------------
            case 'Neos\ContentRepository\Domain\Model\Workspace::beforeNodePublishing':
                /**
                 * @var NodeInterface $node
                 * @var Workspace $targetWorkspace
                 */
                [$node, $targetWorkspace] = $args;
                $this->systemLogger->debug(
                    'beforeNodePublishing: ' . $node->getIdentifier()
                    . ' => ' . $targetWorkspace->getName(),
                    ['packageKey' => 'NEOSidekick.AiAssistant']
                );
                // Store the "before" data - get the original node from the target workspace
                $this->nodePublishingWorkspaceName = $targetWorkspace->getName();
                $closestDocumentNodeIdentifier = $this->getClosestDocumentNodeIdentifier($node);
                // Get the original node from the target workspace (before changes)
                $originalNode = null;
                try {
                    $context = $this->createContentContext($targetWorkspace->getName(), $node->getDimensions());
                    $originalNode = $context->getNodeByIdentifier($node->getIdentifier());
                } catch (\Exception $e) {
                    $this->systemLogger->warning('Could not fetch original node from target workspace: ' . $e->getMessage(), [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                }

                $originalDocumentNode = null;
                try {
                    $context = $this->createContentContext($targetWorkspace->getName(), $node->getDimensions());
                    $originalDocumentNode = $context->getNodeByIdentifier($closestDocumentNodeIdentifier);
                } catch (\Exception $e) {
                    $this->systemLogger->warning('Could not fetch original document from target workspace: ' . $e->getMessage(), [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                }

                $findDocumentNodeFactory = new FindDocumentNodeDataFactory();
                $actionRequestFactory = new ActionRequestFactory();
                $actionRequest = $actionRequestFactory->createActionRequest(ServerRequest::fromGlobals());
                $controllerContext = new ControllerContext($actionRequest, new ActionResponse(), new Arguments(), new UriBuilder());
                if (!isset($this->nodePublishingData[$originalDocumentNode->getPath()])) {
                    $this->nodePublishingData[$originalDocumentNode->getPath()] = [
                        'documentNode' => $findDocumentNodeFactory->createFromNode($originalDocumentNode, $controllerContext),
                        'contentChanges' => []
                    ];
                }

                if ($originalNode) {
                    $this->nodePublishingData[$originalDocumentNode->getPath()]['contentChanges'][$node->getPath()]['before'] =
                        $this->renderNodeArray($originalNode, includeProperties: true);
                } else {
                    // If we can't get the original node, this might be a new node being created
                    $this->nodePublishingData[$originalDocumentNode->getPath()]['contentChanges'][$node->getPath()]['before'] = null;
                }
                break;

            case 'Neos\ContentRepository\Domain\Model\Workspace::afterNodePublishing':
                [$node, $workspace] = $args;
                $this->systemLogger->debug(
                    'afterNodePublishing: ' . $node->getIdentifier()
                    . ' => ' . $workspace->getName(),
                    ['packageKey' => 'NEOSidekick.AiAssistant']
                );
                $closestDocumentNodeIdentifier = $this->getClosestDocumentNodeIdentifier($node);
                try {
                    $context = $this->createContentContext($workspace->getName(), $node->getDimensions());
                    $closestDocumentNode = $context->getNodeByIdentifier($closestDocumentNodeIdentifier);
                } catch (\Exception $e) {
                    $this->systemLogger->warning('Could not fetch original node from target workspace: ' . $e->getMessage(), [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                }
                // Store the "after" data - but only if the node actually exists in the target workspace
                // If the node was deleted, it won't exist in the target workspace, so we don't store "after" data
                $nodeExistsInTargetWorkspace = null;
                try {
                    $context = $this->createContentContext($workspace->getName(), $node->getDimensions());
                    $nodeExistsInTargetWorkspace = $context->getNodeByIdentifier($node->getIdentifier());
                } catch (\Exception $e) {
                    $this->systemLogger->warning('Could not verify node existence in target workspace: ' . $e->getMessage(), [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                }

                if ($nodeExistsInTargetWorkspace) {
                    // Node exists in target workspace - it was created or updated
                    $this->nodePublishingData[$closestDocumentNode->getPath()]['contentChanges'][$node->getPath()]['after'] =
                        $this->renderNodeArray($nodeExistsInTargetWorkspace, includeProperties: true);
                } else {
                    // Node doesn't exist in target workspace yet - might be a newly created node
                    // or a deleted node. For new nodes, use the source node data as fallback
                    if (!isset($this->nodePublishingData[$node->getIdentifier()]['before'])) {
                        // If there's no 'before' data, this is likely a new node
                        $this->systemLogger->debug('Newly created node, using source node data: ' . $node->getIdentifier(), [
                            'packageKey' => 'NEOSidekick.AiAssistant'
                        ]);
                        $this->nodePublishingData[$closestDocumentNode->getPath()]['contentChanges'][$node->getPath()]['after'] =
                            $this->renderNodeArray($node, includeProperties: true);
                    } else {
                        // There is 'before' data but no node in target workspace - it was deleted
                        $this->systemLogger->debug('Node was deleted: ' . $node->getIdentifier(), [
                            'packageKey' => 'NEOSidekick.AiAssistant'
                        ]);
                    }
                }
                break;

            default:
                // You could handle or ignore other signals
                break;
        }
    }

    /**
     * Called at the end of this object's lifecycle.
     * We'll send a single "WorkspacePublished" event for each workspace that had publishing.
     * This includes all nodes that were created, updated, or removed.
     */
    public function shutdownObject(): void
    {
        if (empty($this->nodePublishingData)) {
            return;
        }

        $this->systemLogger->debug('Node Publishing Data:', [
            ...$this->nodePublishingData
        ]);

        $eventName = 'workspacePublished';

        $this->systemLogger->debug('Publishing Data (before sending):', $this->nodePublishingData);
        $changes = [];
        foreach ($this->nodePublishingData as $nodeIdentifier => $states) {
            $before = $states['before'] ?? null;
            $after  = $states['after'] ?? null;

            // Skip if both before and after are null (shouldn't happen but safety check)
            if ($before === null && $after === null) {
                $this->systemLogger->warning('Skipping node with no before/after data: ' . $nodeIdentifier, [
                    'packageKey' => 'NEOSidekick.AiAssistant'
                ]);
                continue;
            }

            // If there's no "before" => created
            // If there's no "after" => removed
            // Otherwise => updated
            if ($before === null && $after !== null) {
                $changeType = 'created';
            } elseif ($before !== null && $after === null) {
                $changeType = 'removed';
            } else {
                $changeType = 'updated';
            }

            // Create nodeContextPath object from the AFTER node if possible; Fallback to BEFORE if node was removed
            $nodeContextPath = [
                'identifier' => $after['identifier'] ?? $before['identifier'] ?? $nodeIdentifier,
                'path' => $after['path'] ?? $before['path'] ?? 'unknown',
                'workspace' => $after['workspace'] ?? $before['workspace'] ?? $this->nodePublishingWorkspaceName ?? 'unknown',
                'dimensions' => $after['dimensions'] ?? $before['dimensions'] ?? []
            ];
            $name = $after['name'] ?? $before['name'] ?? 'unknown';

            // propertiesBefore/propertiesAfter can be null
            $propertiesBefore = $before['properties'] ?? null;
            $propertiesAfter  = $changeType === 'removed' ? null : ($after['properties'] ?? null);

            $nodeChange = new NodeChangeDto($nodeContextPath, $name, $changeType, $propertiesBefore, $propertiesAfter);

            $changes[] = $nodeChange->toArray();
        }

        $workspacePublishedDto = new WorkspacePublishedDto('WorkspacePublished', $this->nodePublishingWorkspaceName, $changes);

//        $this->sendWebhookRequests(
//            $eventName,
//            $workspacePublishedDto->toArray()
//        );

        $this->systemLogger->debug('Publishing Data (before cleanup):', $this->nodePublishingData);
        $this->nodePublishingData = [];
    }

    /**
     * @param NodeInterface $node
     * @param bool $includeProperties
     *
     * @return array
     *
     * @deprecated moved to NEOSidekick\AiAssistant\Utility\ArrayConverter
     */
    private function renderNodeArray(
        NodeInterface $node,
        bool $includeProperties = false
    ): array {
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

    private function renderWorkspaceArray(Workspace $workspace): array
    {
        return [
            'name' => $workspace->getName(),
            'title' => $workspace->getTitle(),
            'description' => $workspace->getDescription()
        ];
    }

    private function renderNodePropertyChangeArray(
        Node $node,
        string $propertyName,
        mixed $oldValue,
        mixed $newValue
    ): array {
        return [
            'identifier' => $node->getIdentifier(),
            'propertyName' => $propertyName,
            'oldValue' => $oldValue,
            'newValue' => $newValue
        ];
    }

    private function sendWebhookRequests(string $eventName, array $payload): void
    {
        if (empty($this->endpoints[$eventName])) {
            return;
        }
        $endpointUrls = $this->endpoints[$eventName];

        foreach ($endpointUrls as $endpointUrl) {
            $this->sendWebhookRequest($endpointUrl, $payload);
        }
    }

    private function sendWebhookRequest(string $url, array $payload): void
    {
        try {
            $client = new Client();
            $client->post($url, [
                'json' => $payload
            ]);
        } catch (\Exception $e) {
            $this->systemLogger->error('Webhook request failed: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'exception' => $e
            ]);
        }
    }

    private function getEventNameFromSignal(string $signalClassAndMethod): string
    {
        if (str_contains($signalClassAndMethod, '::')) {
            return substr($signalClassAndMethod, strrpos($signalClassAndMethod, '::') + 2);
        }
        return $signalClassAndMethod;
    }

    /**
     * @param NodeInterface $node
     *
     * @return string
     */
    private function getClosestDocumentNodeIdentifier(NodeInterface $node): string
    {
        $closestDocumentNode = $node;
        do {
            if ($closestDocumentNode->getNodeType()->isAggregate()) {
                $closestDocumentNodeIdentifier = $node->getIdentifier();
                break;
            }

            $closestDocumentNode = $closestDocumentNode->getParent();
        } while ($closestDocumentNode !== null);
        return $closestDocumentNodeIdentifier;
    }
}

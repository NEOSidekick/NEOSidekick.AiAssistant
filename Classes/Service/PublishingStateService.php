<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\ContentChangeDto;
use NEOSidekick\AiAssistant\Dto\PublishingState;
use NEOSidekick\AiAssistant\Dto\WorkspacePublishedDto;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use Psr\Log\LoggerInterface;

/**
 * Service for managing state during the publishing process
 *
 * @Flow\Scope("singleton")
 */
class PublishingStateService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ApiFacade
     */
    protected $apiFacade;

    /**
     * @var PublishingState
     */
    protected $publishingState;

    /**
     * @Flow\InjectConfiguration(path="webhooks.endpoints")
     * @var array
     */
    protected $endpoints = [];

    /**
     * Initialize the publishing state
     */
    public function initializeObject(): void
    {
        $this->publishingState = new PublishingState();
    }

    /**
     * Get the current publishing state
     *
     * @return PublishingState
     */
    public function getPublishingState(): PublishingState
    {
        return $this->publishingState;
    }

    /**
     * Called at the end of this object's lifecycle.
     * We'll send a single "WorkspacePublished" event for each workspace that had publishing.
     * This includes all nodes that were created, updated, or removed.
     */
    public function shutdownObject(): void
    {
        if (!$this->publishingState->hasDocumentChangeSets()) {
            return;
        }

        $this->systemLogger->debug('Publishing Data (before sending):', $this->publishingState->toArray());

        $eventName = 'workspacePublished';

        // Iterate over all document nodes in publishingState
        foreach ($this->publishingState->getDocumentChangeSets() as $documentPath => $documentChangeSet) {
            $documentNode = $documentChangeSet->getDocumentNode();

            if ($documentNode === null) {
                $this->systemLogger->warning('Document node data missing for path: ' . $documentPath, [
                    'packageKey' => 'NEOSidekick.AiAssistant'
                ]);
                continue;
            }

            $this->systemLogger->debug('Processing document node:', [
                'path' => $documentPath,
                'documentNode' => $documentNode
            ]);

            $changes = [];
            // Process content changes for this document
            /** @var ContentChangeDto $contentChange */
            foreach ($documentChangeSet->getContentChanges() as $contentChange) {
                $changeArray = $contentChange->toArray();
                if (!empty($changeArray)) {
                    $changes[] = $changeArray;
                }
            }

            // Create a WorkspacePublishedDto for this document node
            $workspacePublishedDto = new WorkspacePublishedDto(
                'WorkspacePublished',
                $this->publishingState->getWorkspaceName(),
                $changes
            );

            // Log the document node and its changes
            $this->systemLogger->debug('Document node with changes:', [
                'documentPath' => $documentPath,
                'documentNode' => $documentNode,
                'changes' => $changes,
                'dto' => $workspacePublishedDto->toArray()
            ]);

            // Send webhook for this document node
            if (!empty($this->endpoints)) {
                $this->apiFacade->sendWebhookRequests($eventName, $workspacePublishedDto->toArray(), $this->endpoints);
            }
        }

        $this->systemLogger->debug('Publishing Data (before cleanup):', $this->publishingState->toArray());

        // Reset the publishing state
        $this->publishingState = new PublishingState();
    }
}

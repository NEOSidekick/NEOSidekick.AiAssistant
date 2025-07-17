<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\DocumentChangeSet;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Utility\NodeTreeUtility;
use Psr\Log\LoggerInterface;

/**
 * Listener for node publishing signals
 *
 * @Flow\Scope("singleton")
 */
class NodePublishingListener
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var NodeDataService
     */
    protected $nodeDataService;

    /**
     * @Flow\Inject
     * @var PublishingStateService
     */
    protected $publishingStateService;

    /**
     * @Flow\Inject
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

    /**
     * Handle the beforeNodePublishing signal
     *
     * @param NodeInterface $node The node being published
     * @param Workspace $targetWorkspace The workspace the node is being published to
     * @return void
     */
    public function handleBeforeNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        $this->systemLogger->debug(
            'beforeNodePublishing: ' . $node->getIdentifier()
            . ' => ' . $targetWorkspace->getName(),
            ['packageKey' => 'NEOSidekick.AiAssistant']
        );

        // Set the workspace name in the publishing state
        $publishingState = $this->publishingStateService->getPublishingState();
        $publishingState->setWorkspaceName($targetWorkspace->getName());

        // Get the closest document node identifier
        $closestDocumentNodeIdentifier = NodeTreeUtility::getClosestDocumentNodeIdentifier($node);
        $this->systemLogger->debug('ClosestDocumentNodeIdentifier: ' . $closestDocumentNodeIdentifier);

        // Get the original document node from the target workspace
        $originalDocumentNode = $this->nodeDataService->findNodeInWorkspace(
            $closestDocumentNodeIdentifier,
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        if ($originalDocumentNode === null) {
            $this->systemLogger->warning('Could not fetch original document from target workspace', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentNodeIdentifier' => $closestDocumentNodeIdentifier
            ]);
            return;
        }

        // Create a document change set if it doesn't exist yet
        $documentPath = $originalDocumentNode->getPath();
        if (!$publishingState->hasDocumentChangeSet($documentPath)) {
            // Create document node data and add it to the publishing state
            $documentNodeData = $this->findDocumentNodeDataFactory->createFromNodeAndGlobals($originalDocumentNode)->jsonSerialize();
            $documentChangeSet = new DocumentChangeSet($documentNodeData);
            $publishingState->addDocumentChangeSet($documentPath, $documentChangeSet);
        }

        // Get the original node from the target workspace (before changes)
        $originalNode = $this->nodeDataService->findNodeInWorkspace(
            $node->getIdentifier(),
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        // Add the content change to the document change set
        $documentChangeSet = $publishingState->getDocumentChangeSet($documentPath);
        $beforeState = $originalNode ? $this->nodeDataService->renderNodeArray($originalNode, true) : null;
        $documentChangeSet->addContentChange($node->getPath(), $beforeState, null);
    }

    /**
     * Handle the afterNodePublishing signal
     *
     * @param NodeInterface $node The node that was published
     * @param Workspace $workspace The workspace the node was published to
     * @return void
     */
    public function handleAfterNodePublishing(NodeInterface $node, Workspace $workspace): void
    {
        $this->systemLogger->debug(
            'afterNodePublishing: ' . $node->getIdentifier()
            . ' => ' . $workspace->getName(),
            ['packageKey' => 'NEOSidekick.AiAssistant']
        );

        // Get the closest document node identifier
        $closestDocumentNodeIdentifier = NodeTreeUtility::getClosestDocumentNodeIdentifier($node);

        // Get the document node from the target workspace
        $closestDocumentNode = $this->nodeDataService->findNodeInWorkspace(
            $closestDocumentNodeIdentifier,
            $workspace->getName(),
            $node->getDimensions()
        );

        if ($closestDocumentNode === null) {
            $this->systemLogger->warning('Could not fetch document node from target workspace', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentNodeIdentifier' => $closestDocumentNodeIdentifier
            ]);
            return;
        }

        // Get the publishing state
        $publishingState = $this->publishingStateService->getPublishingState();
        $documentPath = $closestDocumentNode->getPath();

        // If we don't have a document change set for this document, something went wrong
        if (!$publishingState->hasDocumentChangeSet($documentPath)) {
            $this->systemLogger->warning('No document change set found for document', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentPath' => $documentPath
            ]);
            return;
        }

        // Get the document change set
        $documentChangeSet = $publishingState->getDocumentChangeSet($documentPath);

        // Check if the node exists in the target workspace after publishing
        $nodeExistsInTargetWorkspace = $this->nodeDataService->findNodeInWorkspace(
            $node->getIdentifier(),
            $workspace->getName(),
            $node->getDimensions()
        );

        // Update the content change with the "after" state
        if ($nodeExistsInTargetWorkspace) {
            // Node exists in target workspace - it was created or updated
            $afterState = $this->nodeDataService->renderNodeArray($nodeExistsInTargetWorkspace, true);
        } else {
            // Node doesn't exist in target workspace - it was deleted
            $afterState = null;
        }

        // Get the existing content change
        $contentChanges = $documentChangeSet->getContentChanges();
        $nodePath = $node->getPath();

        // If there's no existing content change, create one
        if (!isset($contentChanges[$nodePath])) {
            // This is a new node that wasn't in the target workspace before
            $documentChangeSet->addContentChange($nodePath, null, $afterState);
        } else {
            // Update the existing content change with the "after" state
            $beforeState = $contentChanges[$nodePath]['before'];
            $documentChangeSet->addContentChange($nodePath, $beforeState, $afterState);
        }
    }
}

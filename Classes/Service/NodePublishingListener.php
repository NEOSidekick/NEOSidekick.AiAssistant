<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\ContentChangeDto;
use NEOSidekick\AiAssistant\Dto\DocumentChangeSet;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Utility\NodeTreeUtility;
use Psr\Log\LoggerInterface;

/**
 * Listener for node publishing signals
 *
 * Collects document change sets during publishing:
 * - beforeNodePublishing: Records the "before" state and initializes DocumentChangeSets
 * - afterNodePublishing: Refreshes document node data and records the "after" state
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
     * Initializes a DocumentChangeSet with preliminary document node data from the target workspace
     * and records the "before" state of the node being published.
     *
     * For new documents (not yet in target workspace), an empty DocumentChangeSet is created
     * as a placeholder — it will be populated in afterNodePublishing.
     *
     * @param NodeInterface $node The node being published
     * @param Workspace $targetWorkspace The workspace the node is being published to
     * @return void
     */
    public function handleBeforeNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        $publishingState = $this->publishingStateService->getPublishingState();
        $publishingState->setWorkspaceName($targetWorkspace->getName());

        $closestDocumentNodeIdentifier = NodeTreeUtility::getClosestDocumentNodeIdentifier($node);
        if ($closestDocumentNodeIdentifier === null) {
            return;
        }

        $documentPath = $this->resolveDocumentPathAndInitializeChangeSet($node, $closestDocumentNodeIdentifier, $targetWorkspace, $publishingState);
        if ($documentPath === null) {
            return;
        }

        $this->recordBeforeState($node, $targetWorkspace, $publishingState->getDocumentChangeSet($documentPath));
    }

    /**
     * Handle the afterNodePublishing signal
     *
     * Refreshes the document node data with the now-published state (properties, URIs)
     * and records the "after" state of the node.
     *
     * @param NodeInterface $node The node that was published
     * @param Workspace $targetWorkspace The workspace the node was published to
     * @return void
     */
    public function handleAfterNodePublishing(NodeInterface $node, Workspace $targetWorkspace): void
    {
        $closestDocumentNodeIdentifier = NodeTreeUtility::getClosestDocumentNodeIdentifier($node);
        if ($closestDocumentNodeIdentifier === null) {
            return;
        }

        $closestDocumentNode = $this->resolveDocumentNodeAfterPublishing($node, $closestDocumentNodeIdentifier, $targetWorkspace);
        if ($closestDocumentNode === null) {
            return;
        }

        $publishingState = $this->publishingStateService->getPublishingState();
        $documentPath = $closestDocumentNode->getPath();

        if (!$publishingState->hasDocumentChangeSet($documentPath)) {
            return;
        }

        $documentChangeSet = $publishingState->getDocumentChangeSet($documentPath);

        $this->refreshDocumentNodeDataIfNeeded($node, $closestDocumentNodeIdentifier, $closestDocumentNode, $documentPath, $documentChangeSet, $publishingState);
        $this->recordAfterState($node, $targetWorkspace, $documentChangeSet);
    }

    /**
     * Resolve the document path and initialize a DocumentChangeSet if one does not exist yet.
     *
     * For existing documents: creates a DocumentChangeSet with preliminary data from the target workspace.
     * For new documents: creates an empty placeholder DocumentChangeSet.
     *
     * @return string|null The document path, or null if the document node could not be resolved
     */
    private function resolveDocumentPathAndInitializeChangeSet(
        NodeInterface $node,
        string $closestDocumentNodeIdentifier,
        Workspace $targetWorkspace,
        \NEOSidekick\AiAssistant\Dto\PublishingState $publishingState
    ): ?string {
        // Try to find the document node in the target workspace (exists for updates, null for new documents)
        $documentNodeInTarget = $this->nodeDataService->findNodeInWorkspace(
            $closestDocumentNodeIdentifier,
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        if ($documentNodeInTarget !== null) {
            $documentPath = $documentNodeInTarget->getPath();
            if (!$publishingState->hasDocumentChangeSet($documentPath)) {
                // Preliminary data from target workspace — will be refreshed in afterNodePublishing
                // when the document node itself is published
                $documentNodeData = $this->findDocumentNodeDataFactory->createFromNodeAndGlobals($documentNodeInTarget)->jsonSerialize();
                $publishingState->addDocumentChangeSet($documentPath, new DocumentChangeSet($documentNodeData));
            }
            return $documentPath;
        }

        // New document: find it in the source workspace to determine its path
        $documentNodeInSource = $this->nodeDataService->findNodeInWorkspace(
            $closestDocumentNodeIdentifier,
            $node->getWorkspace()->getName(),
            $node->getDimensions()
        );

        if ($documentNodeInSource === null) {
            $this->systemLogger->warning('Could not find document node in any workspace', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentNodeIdentifier' => $closestDocumentNodeIdentifier
            ]);
            return null;
        }

        $documentPath = $documentNodeInSource->getPath();
        if (!$publishingState->hasDocumentChangeSet($documentPath)) {
            // Empty placeholder — will be populated in afterNodePublishing
            $publishingState->addDocumentChangeSet($documentPath, new DocumentChangeSet([]));
        }
        return $documentPath;
    }

    /**
     * Record the "before" state of a node being published.
     */
    private function recordBeforeState(NodeInterface $node, Workspace $targetWorkspace, DocumentChangeSet $documentChangeSet): void
    {
        $originalNode = $this->nodeDataService->findNodeInWorkspace(
            $node->getIdentifier(),
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        $beforeState = $originalNode ? $this->nodeDataService->createNodeDataDto($originalNode, true) : null;
        $documentChangeSet->addContentChange($node->getPath(), new ContentChangeDto($beforeState, null));
    }

    /**
     * Resolve the closest document node after publishing.
     *
     * If the published node IS the document node, use it directly (avoids lookup issues
     * where the node may not yet be findable via identifier in the target workspace).
     * Otherwise, look it up in the target workspace.
     */
    private function resolveDocumentNodeAfterPublishing(
        NodeInterface $node,
        string $closestDocumentNodeIdentifier,
        Workspace $targetWorkspace
    ): ?NodeInterface {
        if ($node->getIdentifier() === $closestDocumentNodeIdentifier) {
            return $node;
        }

        $documentNode = $this->nodeDataService->findNodeInWorkspace(
            $closestDocumentNodeIdentifier,
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        if ($documentNode === null) {
            $this->systemLogger->warning('Could not find document node in target workspace after publishing', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentNodeIdentifier' => $closestDocumentNodeIdentifier
            ]);
        }

        return $documentNode;
    }

    /**
     * Refresh the document node data in the DocumentChangeSet when the document node itself is published.
     *
     * This is necessary because:
     * - For new documents: the DocumentChangeSet was initialized empty
     * - For existing documents: the DocumentChangeSet contains stale data from the target workspace
     *   (before state) and needs to reflect the newly published properties and URIs
     */
    private function refreshDocumentNodeDataIfNeeded(
        NodeInterface $publishedNode,
        string $closestDocumentNodeIdentifier,
        NodeInterface $closestDocumentNode,
        string $documentPath,
        DocumentChangeSet $documentChangeSet,
        \NEOSidekick\AiAssistant\Dto\PublishingState $publishingState
    ): void {
        if ($publishedNode->getIdentifier() !== $closestDocumentNodeIdentifier) {
            return;
        }

        $documentNodeData = $this->findDocumentNodeDataFactory->createFromNodeAndGlobals($closestDocumentNode)->jsonSerialize();
        if (empty($documentNodeData['publicUri']) && empty($documentNodeData['previewUri'])) {
            $this->systemLogger->warning('Could not generate URIs for document, removing DocumentChangeSet', [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'documentPath' => $documentPath
            ]);
            $publishingState->removeDocumentChangeSet($documentPath);
            return;
        }

        $documentChangeSet->setDocumentNode($documentNodeData);
    }

    /**
     * Record the "after" state of a node that was published.
     */
    private function recordAfterState(NodeInterface $node, Workspace $targetWorkspace, DocumentChangeSet $documentChangeSet): void
    {
        $nodeInTargetWorkspace = $this->nodeDataService->findNodeInWorkspace(
            $node->getIdentifier(),
            $targetWorkspace->getName(),
            $node->getDimensions()
        );

        $afterState = $nodeInTargetWorkspace ? $this->nodeDataService->createNodeDataDto($nodeInTargetWorkspace, true) : null;

        $contentChanges = $documentChangeSet->getContentChanges();
        $existingChange = $contentChanges[$node->getPath()] ?? null;
        $beforeState = $existingChange?->before;

        $documentChangeSet->addContentChange($node->getPath(), new ContentChangeDto($beforeState, $afterState));
    }
}

<?php

namespace NEOSidekick\AiAssistant\Dto;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Data Transfer Object for a document node and its content changes during publishing
 */
final class DocumentChangeSet
{
    /**
     * The document node data
     * @var array
     */
    private array $documentNode;

    /**
     * Array of content changes, keyed by node path
     * @var array<string, array>
     */
    private array $contentChanges = [];

    /**
     * @param array $documentNode The document node data
     */
    public function __construct(array $documentNode)
    {
        $this->documentNode = $documentNode;
    }

    /**
     * Add a content change to this document change set
     *
     * @param string $nodePath The path of the node that changed
     * @param array|null $beforeState The state of the node before the change
     * @param array|null $afterState The state of the node after the change
     * @return void
     */
    public function addContentChange(string $nodePath, ?array $beforeState, ?array $afterState): void
    {
        $this->contentChanges[$nodePath] = [
            'before' => $beforeState,
            'after' => $afterState
        ];
    }

    /**
     * Get the document node data
     *
     * @return array
     */
    public function getDocumentNode(): array
    {
        return $this->documentNode;
    }

    /**
     * Get the content changes
     *
     * @return array<string, array>
     */
    public function getContentChanges(): array
    {
        return $this->contentChanges;
    }

    /**
     * Convert this DTO to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'documentNode' => $this->documentNode,
            'contentChanges' => $this->contentChanges
        ];
    }
}

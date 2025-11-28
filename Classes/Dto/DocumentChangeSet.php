<?php

namespace NEOSidekick\AiAssistant\Dto;


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
     * @var array<string, ContentChangeDto>
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
     * @param ContentChangeDto $change The content change DTO
     * @return void
     */
    public function addContentChange(string $nodePath, ContentChangeDto $change): void
    {
        $this->contentChanges[$nodePath] = $change;
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
     * @return array<string, ContentChangeDto>
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

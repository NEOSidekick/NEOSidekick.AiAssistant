<?php

namespace NEOSidekick\AiAssistant\Dto;

/**
 * Data Transfer Object for the state during publishing process
 */
final class PublishingState
{
    /**
     * The name of the workspace being published to
     * @var string|null
     */
    private ?string $workspaceName = null;

    /**
     * Collection of document change sets, keyed by document node path
     * @var array<string, DocumentChangeSet>
     */
    private array $documentChangeSets = [];

    /**
     * Set the workspace name
     *
     * @param string $workspaceName
     * @return void
     */
    public function setWorkspaceName(string $workspaceName): void
    {
        $this->workspaceName = $workspaceName;
    }

    /**
     * Get the workspace name
     *
     * @return string|null
     */
    public function getWorkspaceName(): ?string
    {
        return $this->workspaceName;
    }

    /**
     * Add a document change set
     *
     * @param string $documentPath
     * @param DocumentChangeSet $documentChangeSet
     * @return void
     */
    public function addDocumentChangeSet(string $documentPath, DocumentChangeSet $documentChangeSet): void
    {
        $this->documentChangeSets[$documentPath] = $documentChangeSet;
    }

    /**
     * Get a document change set by path
     *
     * @param string $documentPath
     * @return DocumentChangeSet|null
     */
    public function getDocumentChangeSet(string $documentPath): ?DocumentChangeSet
    {
        return $this->documentChangeSets[$documentPath] ?? null;
    }

    /**
     * Check if a document change set exists
     *
     * @param string $documentPath
     * @return bool
     */
    public function hasDocumentChangeSet(string $documentPath): bool
    {
        return isset($this->documentChangeSets[$documentPath]);
    }

    /**
     * Get all document change sets
     *
     * @return array<string, DocumentChangeSet>
     */
    public function getDocumentChangeSets(): array
    {
        return $this->documentChangeSets;
    }

    /**
     * Check if there are any document change sets
     *
     * @return bool
     */
    public function hasDocumentChangeSets(): bool
    {
        return !empty($this->documentChangeSets);
    }

    /**
     * Convert this DTO to an array
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [
            'workspaceName' => $this->workspaceName,
            'documentChangeSets' => []
        ];

        foreach ($this->documentChangeSets as $documentPath => $documentChangeSet) {
            $result['documentChangeSets'][$documentPath] = $documentChangeSet->toArray();
        }

        return $result;
    }
}

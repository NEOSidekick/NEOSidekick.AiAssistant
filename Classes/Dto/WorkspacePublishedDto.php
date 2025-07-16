<?php

namespace NEOSidekick\AiAssistant\Dto;

final class WorkspacePublishedDto
{
    private string $event;
    private string $workspaceName;

    /** @var array NodeChangeDto[] or arrays */
    private array $changes;

    public function __construct(string $event, string $workspaceName, array $changes)
    {
        $this->event = $event;
        $this->workspaceName = $workspaceName;
        $this->changes = $changes;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'workspaceName' => $this->workspaceName,
            'changes' => $this->changes
        ];
    }
}

<?php

namespace NEOSidekick\AiAssistant\Dto;

final class WorkspacePublishedDto
{
    private string $event;
    private string $workspaceName;

    /** @var array Arrays from ContentChangeDto->toArray() */
    private array $changes;

    /** @var array List of automation modules to call */
    private array $modulesToCall;

    public function __construct(string $event, string $workspaceName, array $changes, array $modulesToCall = [])
    {
        $this->event = $event;
        $this->workspaceName = $workspaceName;
        $this->changes = $changes;
        $this->modulesToCall = $modulesToCall;
    }

    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'workspaceName' => $this->workspaceName,
            'changes' => $this->changes,
            'modulesToCall' => $this->modulesToCall
        ];
    }
}

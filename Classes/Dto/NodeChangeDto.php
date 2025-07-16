<?php

namespace NEOSidekick\AiAssistant\Dto;

final class NodeChangeDto
{
    private array $nodeContextPath;
    private string $name;
    /** @var string created|updated|removed */
    private string $changeType;
    private ?array $propertiesBefore;
    private ?array $propertiesAfter;

    /**
     * @param array $nodeContextPath
     * @param string $name
     * @param string $changeType       "created", "updated", or "removed"
     * @param array|null $propertiesBefore
     * @param array|null $propertiesAfter
     */
    public function __construct(
        array $nodeContextPath,
        string $name,
        string $changeType,
        ?array $propertiesBefore,
        ?array $propertiesAfter
    ) {
        $this->nodeContextPath = $nodeContextPath;
        $this->name = $name;
        $this->changeType = $changeType;
        $this->propertiesBefore = $propertiesBefore;
        $this->propertiesAfter = $propertiesAfter;
    }

    public function toArray(): array
    {
        return [
            'nodeContextPath' => $this->nodeContextPath,
            'name' => $this->name,
            'changeType' => $this->changeType,
            'propertiesBefore' => $this->propertiesBefore,
            'propertiesAfter' => $this->propertiesAfter
        ];
    }
}

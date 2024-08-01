<?php

namespace NEOSidekick\AiAssistant\Dto;

final class FindContentNodeData implements \JsonSerializable
{
    /**
     * @var string
     */
    protected string $identifier;

    /**
     * @var string
     */
    protected string $nodeContextPath;

    /**
     * @var string
     */
    protected string $nodeTypeName;

    /**
     * @var array
     */
    protected array $properties;

    /**
     * @var string
     */
    protected string $language;

    public function __construct(
        string $identifier,
        string $nodeContextPath,
        string $nodeTypeName,
        array $properties,
        string $language
    ) {
        $this->identifier = $identifier;
        $this->nodeContextPath = $nodeContextPath;
        $this->nodeTypeName = $nodeTypeName;
        $this->properties = $properties;
        $this->language = $language;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getNodeContextPath(): string
    {
        return $this->nodeContextPath;
    }

    public function getNodeTypeName(): string
    {
        return $this->nodeTypeName;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'ContentNode',
            'identifier' => $this->identifier,
            'nodeContextPath' => $this->nodeContextPath,
            'nodeTypeName' => $this->nodeTypeName,
            'properties' => $this->properties,
            'language' => $this->language
        ];
    }
}

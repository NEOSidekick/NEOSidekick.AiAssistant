<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class UpdateNodeProperties implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $nodeContextPath;

    /**
     * @var array
     */
    protected array $properties;

    /**
     * @param string $nodeContextPath
     * @param array $properties
     */
    public function __construct(string $nodeContextPath, array $properties)
    {
        $this->nodeContextPath = $nodeContextPath;
        $this->properties = $properties;
    }

    public function getNodeContextPath(): string
    {
        return $this->nodeContextPath;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param array{
     *     nodeContextPath: string,
     *     properties: string
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['nodeContextPath'],
            $array['properties'],
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'nodeContextPath' => $this->nodeContextPath,
            'properties' => $this->properties,
        ];
    }
}

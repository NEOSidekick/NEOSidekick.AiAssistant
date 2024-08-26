<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class UpdateAssetData implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $identifier;
    /**
     * @var array
     */
    protected array $properties;

    /**
     * @param string $identifier
     * @param array $properties
     */
    public function __construct(
        string $identifier,
        array $properties
    ) {
        $this->identifier = $identifier;
        $this->properties = $properties;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public static function fromArray(array $array): self
    {
        return new self(
            $array['identifier'],
            $array['properties']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'Asset',
            'identifier' => $this->identifier,
            'properties' => $this->properties
        ];
    }
}

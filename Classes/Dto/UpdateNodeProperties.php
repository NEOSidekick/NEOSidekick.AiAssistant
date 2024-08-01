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
     * @var array
     */
    protected array $images;

    /**
     * @param string $nodeContextPath
     * @param array  $properties
     * @param array  $images
     */
    public function __construct(string $nodeContextPath, array $properties, array $images = [])
    {
        $this->nodeContextPath = $nodeContextPath;
        $this->properties = $properties;
        $this->images = $images;
    }

    public function getNodeContextPath(): string
    {
        return $this->nodeContextPath;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getImages(): array
    {
        return $this->images;
    }

    /**
     * @param array{
     *     nodeContextPath: string,
     *     properties: string[],
     *     images: array[]
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['nodeContextPath'],
            $array['properties'],
            $array['images']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'nodeContextPath' => $this->nodeContextPath,
            'properties' => $this->properties,
            'images' => $this->images,
        ];
    }
}

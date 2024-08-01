<?php

namespace NEOSidekick\AiAssistant\Dto;

use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\ValueObject
 * @Flow\Proxy(false)
 */
final class FindDocumentNodeData implements JsonSerializable
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
     * @var string
     */
    protected string $publicUri;

    /**
     * @var string
     */
    protected string $previewUri;

    /**
     * @var array
     */
    protected array $properties;

    /**
     * @var string
     */
    protected string $language;

    /**
     * @var array<FindContentNodeData>
     */
    protected array $relevantContentNodes;

    /**
     * @param string $identifier
     * @param string $nodeContextPath
     * @param string $nodeTypeName
     * @param string $publicUri
     * @param string $previewUri
     * @param array  $properties
     * @param string $language
     * @param array $relevantContentNodes
     */
    public function __construct(
        string $identifier,
        string $nodeContextPath,
        string $nodeTypeName,
        string $publicUri,
        string $previewUri,
        array $properties,
        string $language,
        array $relevantContentNodes = []
    ) {
        $this->identifier = $identifier;
        $this->nodeContextPath = $nodeContextPath;
        $this->nodeTypeName = $nodeTypeName;
        $this->publicUri = $publicUri;
        $this->previewUri = $previewUri;
        $this->properties = $properties;
        $this->language = $language;
        $this->relevantContentNodes = $relevantContentNodes;
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

    public function getPublicUri(): string
    {
        return $this->publicUri;
    }

    public function getPreviewUri(): string
    {
        return $this->previewUri;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getRelevantContentNodes(): array
    {
        return $this->relevantContentNodes;
    }

    public function withAddedRelevantContentNode(FindContentNodeData $contentNode): self
    {
        return new self(
            $this->identifier,
            $this->nodeContextPath,
            $this->nodeTypeName,
            $this->publicUri,
            $this->previewUri,
            $this->properties,
            $this->language,
            [...$this->relevantContentNodes, $contentNode]
        );
    }

    /**
     * @param array{
     *     identifier: string,
     *     nodeContextPath: string,
     *     nodeTypeName: string,
     *     publicUri: string,
     *     previewUri: string,
     *     properties: array,
     *     language: string
     * } $array
     *
     * @return self
     */
    public static function fromArray(array $array): self
    {
        return new self(
            $array['identifier'],
            $array['nodeContextPath'],
            $array['nodeTypeName'],
            $array['publicUri'],
            $array['previewUri'],
            $array['properties'],
            $array['language']
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'DocumentNode',
            'identifier' => $this->identifier,
            'nodeContextPath' => $this->nodeContextPath,
            'nodeTypeName' => $this->nodeTypeName,
            'publicUri' => $this->publicUri,
            'previewUri' => $this->previewUri,
            'properties' => $this->properties,
            'language' => $this->language,
            'relevantContentNodes' => $this->relevantContentNodes
        ];
    }
}

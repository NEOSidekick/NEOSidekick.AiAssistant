<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for updating node properties.
 *
 * @Flow\Proxy(false)
 */
final class UpdateNodePatch extends AbstractPatch
{
    protected string $operation = 'updateNode';

    private string $nodeId;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    /**
     * Create a patch representing an update to a node's properties.
     *
     * @param string $nodeId Identifier of the target node.
     * @param array<string, mixed> $properties Map of property names to their new values.
     */
    public function __construct(string $nodeId, array $properties)
    {
        $this->nodeId = $nodeId;
        $this->properties = $properties;
    }

    /**
     * Retrieve the node identifier targeted by this patch.
     *
     * @return string The node identifier targeted by the patch.
     */
    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    /**
     * Properties to apply to the node.
     *
     * @return array<string, mixed> The map of property names to their values.
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
         * Create an UpdateNodePatch from an associative array containing the node identifier and property map.
         *
         * The array must contain:
         * - `nodeId`: the target node identifier.
         * - `properties`: an array of property name => value entries.
         *
         * @param array{operation: string, nodeId: string, properties: array<string, mixed>} $data Input data with required keys.
         * @return self
         * @throws \InvalidArgumentException If `nodeId` is missing or if `properties` is missing or not an array.
         */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId'])) {
            throw new \InvalidArgumentException('UpdateNodePatch requires "nodeId"');
        }
        if (!isset($data['properties']) || !is_array($data['properties'])) {
            throw new \InvalidArgumentException('UpdateNodePatch requires "properties" array');
        }

        return new self($data['nodeId'], $data['properties']);
    }

    /**
     * Convert the patch into an associative array suitable for JSON serialization.
     *
     * @return array{operation: string, nodeId: string, properties: array<string, mixed>} Associative array with keys `operation` (patch name), `nodeId` (target node identifier) and `properties` (map of property names to values).
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
            'properties' => $this->properties,
        ];
    }
}
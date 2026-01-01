<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for creating a new node.
 *
 * @Flow\Proxy(false)
 */
final class CreateNodePatch extends AbstractPatch
{
    /**
     * Valid position values for node placement.
     */
    private const VALID_POSITIONS = ['into', 'before', 'after'];

    protected string $operation = 'createNode';

    /**
     * The node to position the new node relative to.
     * For position 'into': this is the parent node.
     * For position 'before'/'after': this is the sibling reference node.
     */
    private string $positionRelativeToNodeId;

    private string $nodeType;

    /**
     * Position: 'into', 'before', or 'after'
     */
    private string $position;

    /**
     * @var array<string, mixed>
     */
    private array $properties;

    /**
     * Initialize the patch describing creation of a node with its type, placement relative to an existing node, and optional properties.
     *
     * @param string $positionRelativeToNodeId The reference node ID used to position the new node.
     * @param string $nodeType The node type identifier to create.
     * @param string $position One of 'into', 'before', or 'after' indicating where to place the new node relative to the reference node.
     * @param array<string,mixed> $properties Additional node properties to set on creation.
     *
     * @throws \InvalidArgumentException If $position is not one of 'into', 'before', or 'after'.
     */
    public function __construct(
        string $positionRelativeToNodeId,
        string $nodeType,
        string $position = 'into',
        array $properties = []
    ) {
        self::validatePosition($position);
        $this->positionRelativeToNodeId = $positionRelativeToNodeId;
        $this->nodeType = $nodeType;
        $this->position = $position;
        $this->properties = $properties;
    }

    /**
         * Ensure the provided position is one of the allowed values.
         *
         * @param string $position The position to validate; must be one of 'into', 'before', 'after'.
         * @throws \InvalidArgumentException If the provided position is not one of the allowed values.
         */
    private static function validatePosition(string $position): void
    {
        if (!in_array($position, self::VALID_POSITIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid position "%s". Must be one of: %s',
                    $position,
                    implode(', ', self::VALID_POSITIONS)
                )
            );
        }
    }

    /**
     * Get the reference node ID used to position the created node.
     *
     * @return string The reference node ID used to position the new node.
     */
    public function getPositionRelativeToNodeId(): string
    {
        return $this->positionRelativeToNodeId;
    }

    /**
     * Node type identifier used for the new node.
     *
     * @return string The node type identifier.
     */
    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    /**
     * The position where the new node should be placed relative to the reference node.
     *
     * @return string One of 'into', 'before', or 'after'.
     */
    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * Properties to assign to the created node.
     *
     * @return array<string,mixed> Associative array of node properties keyed by property name.
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Create a CreateNodePatch instance from an associative array.
     *
     * @param array{operation: string, positionRelativeToNodeId: string, nodeType: string, position?: string, properties?: array<string, mixed>} $data Input data used to construct the patch.
     * @return self A new CreateNodePatch instance.
     * @throws \InvalidArgumentException If 'positionRelativeToNodeId' or 'nodeType' are missing.
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['positionRelativeToNodeId'])) {
            throw new \InvalidArgumentException('CreateNodePatch requires "positionRelativeToNodeId"');
        }
        if (!isset($data['nodeType'])) {
            throw new \InvalidArgumentException('CreateNodePatch requires "nodeType"');
        }

        return new self(
            $data['positionRelativeToNodeId'],
            $data['nodeType'],
            $data['position'] ?? 'into',
            $data['properties'] ?? []
        );
    }

    /**
     * Serialize the patch to an associative array suitable for JSON encoding.
     *
     * @return array{operation: string, positionRelativeToNodeId: string, nodeType: string, position: string, properties: array<string, mixed>} An associative array with keys:
     * - `operation`: the patch operation name,
     * - `positionRelativeToNodeId`: reference node identifier used for positioning,
     * - `nodeType`: the type of node to create,
     * - `position`: placement relative to the reference node (`into`, `before`, or `after`),
     * - `properties`: additional properties for the new node.
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'positionRelativeToNodeId' => $this->positionRelativeToNodeId,
            'nodeType' => $this->nodeType,
            'position' => $this->position,
            'properties' => $this->properties,
        ];
    }
}
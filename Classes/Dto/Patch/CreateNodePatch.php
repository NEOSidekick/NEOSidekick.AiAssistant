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
     * @param string $positionRelativeToNodeId The reference node for positioning
     * @param string $nodeType
     * @param string $position
     * @param array<string, mixed> $properties
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
     * Validate that the position is one of the allowed values.
     *
     * @throws \InvalidArgumentException
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

    public function getPositionRelativeToNodeId(): string
    {
        return $this->positionRelativeToNodeId;
    }

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param array{operation: string, positionRelativeToNodeId: string, nodeType: string, position?: string, properties?: array<string, mixed>} $data
     * @return self
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
     * @return array{operation: string, positionRelativeToNodeId: string, nodeType: string, position: string, properties: array<string, mixed>}
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

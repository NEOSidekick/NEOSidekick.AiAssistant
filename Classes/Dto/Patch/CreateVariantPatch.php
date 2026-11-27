<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * DTO for creating the variant of a page in the requested dimensions, from the request's
 * source dimensions - what the Neos UI offers as "Create empty" and "Create and copy".
 *
 * @Flow\Proxy(false)
 */
final class CreateVariantPatch extends AbstractPatch
{
    protected string $operation = 'createVariant';

    private string $nodeId;

    private bool $copyContent;

    public function __construct(string $nodeId, bool $copyContent)
    {
        $this->nodeId = $nodeId;
        $this->copyContent = $copyContent;
    }

    public function getNodeId(): string
    {
        return $this->nodeId;
    }

    public function shouldCopyContent(): bool
    {
        return $this->copyContent;
    }

    /**
     * @param array{operation: string, nodeId: string, copyContent: bool} $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['nodeId']) || !is_string($data['nodeId'])) {
            throw new \InvalidArgumentException('CreateVariantPatch requires "nodeId"');
        }
        if (!isset($data['copyContent']) || !is_bool($data['copyContent'])) {
            throw new \InvalidArgumentException(
                'CreateVariantPatch requires "copyContent": true copies the page with its content, false creates it empty'
            );
        }

        return new self($data['nodeId'], $data['copyContent']);
    }

    /**
     * @return array{operation: string, nodeId: string, copyContent: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'operation' => $this->operation,
            'nodeId' => $this->nodeId,
            'copyContent' => $this->copyContent,
        ];
    }
}

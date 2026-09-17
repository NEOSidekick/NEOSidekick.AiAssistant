<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Dto\Patch;

use Neos\Flow\Annotations as Flow;

/**
 * A batch-local reference used in place of a node id: `$<ref>` addresses the node created by the
 * patch that declared `ref`, `$<ref>/<childName>` one of its auto-created child nodes.
 *
 * Node ids are UUIDs, so the `$` prefix cannot collide with a stored node.
 *
 * @Flow\Proxy(false)
 */
final class RefAnchor
{
    public const REF_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    public const REF_PATTERN_DESCRIPTION = 'a letter followed by up to 63 letters, digits, "_" or "-"';

    private function __construct(
        private readonly string $ref,
        private readonly ?string $childName
    ) {
    }

    /**
     * Whether the value is a batch-local reference rather than a node id.
     */
    public static function isAnchor(string $value): bool
    {
        return str_starts_with($value, '$');
    }

    /**
     * Parse an anchor field value. Returns null for plain node ids.
     *
     * @throws \InvalidArgumentException If the value starts with `$` but is not `$<ref>` or `$<ref>/<childName>`
     */
    public static function fromAnchor(string $anchor): ?self
    {
        if (!self::isAnchor($anchor)) {
            return null;
        }

        $segments = explode('/', substr($anchor, 1));
        if (count($segments) > 2) {
            throw new \InvalidArgumentException(
                'only one "/<childName>" segment is allowed after the ref'
            );
        }
        if (preg_match(self::REF_PATTERN, $segments[0]) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('the ref must be %s', self::REF_PATTERN_DESCRIPTION)
            );
        }
        $childName = $segments[1] ?? null;
        if ($childName !== null && $childName === '') {
            throw new \InvalidArgumentException('the child name after "/" must not be empty');
        }

        return new self($segments[0], $childName);
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function getChildName(): ?string
    {
        return $this->childName;
    }
}

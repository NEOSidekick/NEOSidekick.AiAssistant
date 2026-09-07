<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service\PatchValidation;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Annotations as Flow;

/**
 * What the validator knows about an anchored node before the batch is executed.
 *
 * Three kinds: a stored node, a pending node (declared with `ref` by an earlier createNode patch)
 * and an auto-created child of a pending node (`$<ref>/<childName>`). Each kind answers the child
 * constraint check the way the repository will apply it at execution: stored anchors keep the
 * parent-type check (`NodeType::allowsChildNodeType`), a pending auto-created child applies the
 * owner type's grandchild constraints (`NodeType::allowsGrandchildNodeType`).
 *
 * @Flow\Proxy(false)
 */
final class NodeDescriptor
{
    private function __construct(
        private readonly string $label,
        private readonly NodeType $nodeType,
        private readonly ?NodeInterface $storedNode,
        private readonly ?string $ref,
        private readonly ?int $declaredAtPatchIndex,
        private readonly ?NodeDescriptor $parent,
        private readonly ?string $autoCreatedChildName
    ) {
    }

    public static function forStoredNode(NodeInterface $node): self
    {
        return new self(
            sprintf('node "%s"', $node->getIdentifier()),
            $node->getNodeType(),
            $node,
            null,
            null,
            null,
            null
        );
    }

    /**
     * @param NodeDescriptor $parent The parent the declaring patch creates the node under
     */
    public static function forPendingNode(string $ref, int $declaredAtPatchIndex, NodeType $nodeType, self $parent): self
    {
        return new self(sprintf('"$%s"', $ref), $nodeType, null, $ref, $declaredAtPatchIndex, $parent, null);
    }

    /**
     * @param string $anchor The anchor as sent, e.g. `$page/main`
     * @param NodeDescriptor $owner The pending node the child is auto-created on
     */
    public static function forAutoCreatedChildOfPendingNode(string $anchor, string $childName, NodeType $childNodeType, self $owner): self
    {
        return new self(sprintf('"%s"', $anchor), $childNodeType, null, null, null, $owner, $childName);
    }

    /**
     * The same pending node after a moveNode patch placed it under a new parent.
     */
    public function withParent(self $parent): self
    {
        return new self(
            $this->label,
            $this->nodeType,
            $this->storedNode,
            $this->ref,
            $this->declaredAtPatchIndex,
            $parent,
            $this->autoCreatedChildName
        );
    }

    /**
     * For messages: `node "<uuid>"`, `"$acc"` or `"$page/main"`.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    public function getNodeType(): NodeType
    {
        return $this->nodeType;
    }

    /**
     * The ref of a pending node (`$<ref>` form only); null for stored nodes and auto-created children.
     */
    public function getRef(): ?string
    {
        return $this->ref;
    }

    public function getDeclaredAtPatchIndex(): ?int
    {
        return $this->declaredAtPatchIndex;
    }

    public function isPendingNode(): bool
    {
        return $this->ref !== null;
    }

    /**
     * The node's parent, or null for a stored node without one.
     */
    public function getParent(): ?self
    {
        if ($this->storedNode !== null) {
            $parentNode = $this->storedNode->getParent();

            return $parentNode === null ? null : self::forStoredNode($parentNode);
        }

        return $this->parent;
    }

    /**
     * Whether a node of the given type may be created under (or moved into) this node.
     */
    public function allowsChildNodeType(NodeType $nodeType): bool
    {
        if ($this->autoCreatedChildName !== null && $this->parent !== null) {
            return $this->parent->getNodeType()->allowsGrandchildNodeType($this->autoCreatedChildName, $nodeType);
        }

        return $this->nodeType->allowsChildNodeType($nodeType);
    }

    /**
     * Names of the given node types that are allowed as children of this node.
     *
     * @param array<string, NodeType>|array<int, NodeType> $candidates
     * @return array<int, string>
     */
    public function allowedChildNodeTypeNames(array $candidates): array
    {
        $names = [];
        foreach ($candidates as $candidate) {
            if ($this->allowsChildNodeType($candidate)) {
                $names[] = $candidate->getName();
            }
        }
        sort($names);

        return $names;
    }

    /**
     * Auto-created child names (`childNodes:` keys) of this node's type.
     *
     * @return array<int, string>
     */
    public function getAutoCreatedChildNames(): array
    {
        return array_keys($this->nodeType->getAutoCreatedChildNodes());
    }

    public function getAutoCreatedChildNodeType(string $childName): ?NodeType
    {
        return $this->nodeType->getAutoCreatedChildNodes()[$childName] ?? null;
    }
}

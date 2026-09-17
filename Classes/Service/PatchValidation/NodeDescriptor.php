<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service\PatchValidation;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\TetheredNodeTypeDefinition;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeName;
use Neos\Flow\Annotations as Flow;

/**
 * What the validator knows about an anchored node before the batch is executed.
 *
 * Three kinds: a stored node, a pending node (declared with `ref` by an earlier createNode patch)
 * and an auto-created (tethered) child of a pending node (`$<ref>/<childName>`). Each kind answers
 * the child constraint check the way the content repository applies it when it handles the
 * command (ConstraintChecks::requireConstraintsImposedByAncestorsAreMet()): a parent that is not
 * tethered imposes its own node type's constraints, and a parent that is a tethered child of its
 * owner imposes the constraints the owner's type declares for that child instead.
 *
 * @Flow\Proxy(false)
 */
final class NodeDescriptor
{
    private ?NodeType $ownerNodeType = null;

    /**
     * Memoized: the owner lookup queries the subgraph, and building the list of allowed child types
     * for a message asks it once per node type of the schema.
     */
    private bool $ownerNodeTypeResolved = false;

    private function __construct(
        private readonly string $label,
        private readonly ?NodeType $nodeType,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly ?Node $storedNode,
        private readonly ?ContentSubgraphInterface $subgraph,
        private readonly ?string $ref,
        private readonly ?int $declaredAtPatchIndex,
        private readonly ?NodeDescriptor $parent,
        private readonly ?NodeName $autoCreatedChildName
    ) {
    }

    /**
     * @param NodeType|null $nodeType Null when the stored node's type is not known to the schema
     */
    public static function forStoredNode(Node $node, ?NodeType $nodeType, ContentSubgraphInterface $subgraph, NodeTypeManager $nodeTypeManager): self
    {
        return new self(
            sprintf('node "%s"', $node->aggregateId->value),
            $nodeType,
            $nodeTypeManager,
            $node,
            $subgraph,
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
        return new self(sprintf('"$%s"', $ref), $nodeType, $parent->nodeTypeManager, null, null, $ref, $declaredAtPatchIndex, $parent, null);
    }

    /**
     * @param string $anchor The anchor as sent, e.g. `$page/main`
     * @param NodeDescriptor $owner The pending node the child is auto-created on
     */
    public static function forAutoCreatedChildOfPendingNode(string $anchor, NodeName $childName, NodeType $childNodeType, self $owner): self
    {
        return new self(sprintf('"%s"', $anchor), $childNodeType, $owner->nodeTypeManager, null, null, null, null, $owner, $childName);
    }

    /**
     * The same pending node after a moveNode patch placed it under a new parent.
     */
    public function withParent(self $parent): self
    {
        return new self(
            $this->label,
            $this->nodeType,
            $this->nodeTypeManager,
            $this->storedNode,
            $this->subgraph,
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

    /**
     * Null only for a stored node whose type is not known to the schema.
     */
    public function getNodeType(): ?NodeType
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
     * The stored node, or null for a node the batch has not created yet.
     */
    public function getStoredNode(): ?Node
    {
        return $this->storedNode;
    }

    /**
     * The parent of a pending node or of an auto-created child of one. Stored nodes answer null: their
     * parent is read from the subgraph by the validator, which also resolves its type.
     */
    public function getPendingParent(): ?self
    {
        return $this->storedNode === null ? $this->parent : null;
    }

    /**
     * Whether a node of the given type may be created under (or moved into) this node.
     */
    public function allowsChildNodeType(NodeType $nodeType): bool
    {
        if (!$this->isTethered() && $this->nodeType !== null && !$this->nodeType->allowsChildNodeType($nodeType)) {
            return false;
        }

        $name = $this->storedNode !== null ? $this->storedNode->name : $this->autoCreatedChildName;
        $ownerNodeType = $name === null ? null : $this->findOwnerNodeType();
        if ($name === null || $ownerNodeType === null || !$ownerNodeType->tetheredNodeTypeDefinitions->contain($name)) {
            return true;
        }

        return $this->nodeTypeManager->isNodeTypeAllowedAsChildToTetheredNode($ownerNodeType->name, $name, $nodeType->name);
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
                $names[] = $candidate->name->value;
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
        if ($this->nodeType === null) {
            return [];
        }

        return $this->nodeType->tetheredNodeTypeDefinitions->map(
            static fn (TetheredNodeTypeDefinition $definition): string => $definition->name->value
        );
    }

    public function getAutoCreatedChildNodeType(NodeName $childName): ?NodeType
    {
        $definition = $this->nodeType?->tetheredNodeTypeDefinitions->get($childName);

        return $definition === null ? null : $this->nodeTypeManager->getNodeType($definition->nodeTypeName);
    }

    private function isTethered(): bool
    {
        return $this->storedNode !== null
            ? $this->storedNode->classification->isTethered()
            : $this->autoCreatedChildName !== null;
    }

    /**
     * The type of the node this one is a (possibly tethered) child of; null when it has no parent or
     * the parent's type is not known to the schema, in which case the repository skips the check too.
     */
    private function findOwnerNodeType(): ?NodeType
    {
        if ($this->ownerNodeTypeResolved) {
            return $this->ownerNodeType;
        }
        $this->ownerNodeTypeResolved = true;

        if ($this->storedNode === null) {
            return $this->ownerNodeType = $this->parent?->getNodeType();
        }

        $ownerNode = $this->subgraph?->findParentNode($this->storedNode->aggregateId);

        return $this->ownerNodeType = ($ownerNode === null ? null : $this->nodeTypeManager->getNodeType($ownerNode->nodeTypeName));
    }
}

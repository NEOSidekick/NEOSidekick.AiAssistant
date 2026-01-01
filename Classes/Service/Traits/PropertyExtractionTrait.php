<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service\Traits;

use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Trait for extracting configured properties from Neos nodes.
 *
 * Provides shared logic for extracting only non-null values
 * from a configurable list of property names.
 */
trait PropertyExtractionTrait
{
    /**
 * Provide the names of node properties to extract.
 *
 * Implementing classes must return an array of property names that should be extracted from a node.
 *
 * @return string[] The node property names to extract.
 */
    abstract protected function getIncludedProperties(): array;

    /**
     * Return the properties configured by getIncludedProperties() from the given node when their values are not null.
     *
     * @param NodeInterface $node The node to extract properties from.
     * @return array<string,mixed> Map of property names to their non-null values.
     */
    protected function extractSelectedProperties(NodeInterface $node): array
    {
        $properties = [];
        foreach ($this->getIncludedProperties() as $propertyName) {
            $value = $node->getProperty($propertyName);
            if ($value !== null) {
                $properties[$propertyName] = $value;
            }
        }
        return $properties;
    }
}

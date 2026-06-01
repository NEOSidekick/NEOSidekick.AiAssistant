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
     * Get the list of properties to include in extraction.
     *
     * Must be implemented by the using class to provide
     * the configured property names.
     *
     * @return array<string> Property names to extract
     */
    abstract protected function getIncludedProperties(): array;

    /**
     * Extract only the configured properties from a node.
     *
     * Iterates over the configured property names and returns
     * only those with non-null values.
     *
     * @param NodeInterface $node The node to extract properties from
     * @return array<string, mixed> Extracted properties (key => value)
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


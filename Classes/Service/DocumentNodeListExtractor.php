<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Service\ContentContext;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;

/**
 * Service to extract document node list from Neos.
 *
 * Traverses the document tree and extracts minimal data needed
 * for LLM agents to discover and navigate pages.
 *
 * @Flow\Scope("singleton")
 */
class DocumentNodeListExtractor
{
    use PropertyExtractionTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';
    private const SITE_TYPE = 'Neos.Neos:Site';

    /**
     * Properties to include in the document list response.
     * Configurable via NEOSidekick.AiAssistant.documentNodeList.includedProperties
     *
     * @Flow\InjectConfiguration(path="documentNodeList.includedProperties")
     * @var array|null
     */
    protected ?array $includedProperties = null;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Get the list of properties to include.
     *
     * @return array
     */
    protected function getIncludedProperties(): array
    {
        return $this->includedProperties ?? ['title', 'metaDescription', 'uriPathSegment'];
    }

    /**
     * Extract all document nodes for a site.
     *
     * @param string $workspace Workspace name (default: 'live')
     * @param array $dimensions Content dimensions
     * @param string|null $siteNodeName Site node name (null = first site)
     * @param string $nodeTypeFilter Filter by NodeType
     * @param int $depth Maximum traversal depth (-1 = unlimited)
     * @return array
     */
    public function extract(
        string $workspace = 'live',
        array $dimensions = [],
        ?string $siteNodeName = null,
        string $nodeTypeFilter = self::DOCUMENT_TYPE,
        int $depth = -1
    ): array {
        // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
        $context = $this->createContentContext($workspace, $dimensions);
        $siteNode = $this->resolveSiteNode($context, $siteNodeName);

        if ($siteNode === null) {
            throw new \InvalidArgumentException('No site found', 1735660100);
        }

        $documents = [];
        $this->traverseDocuments($siteNode, $nodeTypeFilter, $depth, 0, $documents);

        // TODO 9.0 migration: Check if you could change your code to work with the NodeAggregateId value object instead.
        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'site' => [
                'name' => $siteNode->nodeName,
                'nodeType' => $siteNode->nodeTypeName->value,
                'identifier' => $siteNode->aggregateId->value,
            ],
            'documents' => $documents,
            'documentCount' => count($documents),
        ];
    }

    /**
     * Resolve the site node to query.
     */
    private function resolveSiteNode(\Neos\Rector\ContentRepository90\Legacy\LegacyContextStub $context, ?string $siteNodeName): ?\Neos\ContentRepository\Core\Projection\ContentGraph\Node
    {
        // If a specific site is requested, resolve it directly
        if ($siteNodeName !== null) {
            return $context->getNode('/sites/' . $siteNodeName);
        }

        // Try to get the first site from the /sites node
        $sitesNode = $context->getNode('/sites');
        if ($sitesNode === null) {
            return null;
        }

        // Get all child nodes that are sites
        $childNodes = $sitesNode->getChildNodes(self::SITE_TYPE);
        if (!empty($childNodes)) {
            return $childNodes[0];
        }

        // Fallback: get any child node of sites (for sites with custom NodeTypes)
        $allChildNodes = $sitesNode->getChildNodes();
        return $allChildNodes[0] ?? null;
    }

    /**
     * Recursively traverse document nodes.
     */
    private function traverseDocuments(
        \Neos\ContentRepository\Core\Projection\ContentGraph\Node $node,
        string $nodeTypeFilter,
        int $maxDepth,
        int $currentDepth,
        array &$documents
    ): void {
        // Check depth limit
        if ($maxDepth >= 0 && $currentDepth > $maxDepth) {
            return;
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        // Add current node if it matches the filter
        if ($contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName)->isOfType($nodeTypeFilter)) {
            $documents[] = $this->extractDocumentData($node, $currentDepth);
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

        // Traverse child documents
        // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.
        $childDocuments = iterator_to_array($subgraph->findChildNodes($node->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::create()));
        foreach ($childDocuments as $childNode) {
            $this->traverseDocuments($childNode, $nodeTypeFilter, $maxDepth, $currentDepth + 1, $documents);
        }
    }

    /**
     * Extract data from a single document node.
     */
    private function extractDocumentData(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node, int $depth): array
    {
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        // TODO 9.0 migration: Try to remove the iterator_to_array($nodes) call.
        $childDocuments = iterator_to_array($subgraph->findChildNodes($node->aggregateId, \Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter::create()));
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

        // TODO 9.0 migration: Try to remove the (string) cast and make your code more type-safe.
        return [
            'identifier' => $node->getIdentifier(),
            'nodeType' => $node->nodeTypeName->value,
            'path' => (string) $subgraph->findNodePath($node->aggregateId),
            'depth' => $depth,
            'title' => $node->getProperty('title') ?? $node->getName(),
            'uriPath' => $node->getProperty('uriPathSegment') ?? '',
            'properties' => $this->extractSelectedProperties($node),
            'childDocumentCount' => count($childDocuments),
            'isHidden' => $node->isHidden(),
            'isHiddenInMenu' => (bool)$node->isHiddenInIndex(),
        ];
    }
}

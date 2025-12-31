<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Service\ContentContext;

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
    use CreateContentContextTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';
    private const SITE_TYPE = 'Neos.Neos:Site';

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * Properties to include in the document list response.
     * Configurable via NEOSidekick.AiAssistant.documentNodeList.includedProperties
     *
     * @Flow\InjectConfiguration(path="documentNodeList.includedProperties")
     * @var array|null
     */
    protected ?array $includedProperties = null;

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
        $context = $this->createContentContext($workspace, $dimensions);
        $siteNode = $this->resolveSiteNode($context, $siteNodeName);

        if ($siteNode === null) {
            throw new \InvalidArgumentException('No site found', 1735660100);
        }

        $documents = [];
        $this->traverseDocuments($siteNode, $nodeTypeFilter, $depth, 0, $documents);

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'site' => [
                'name' => $siteNode->getName(),
                'nodeType' => $siteNode->getNodeType()->getName(),
                'identifier' => $siteNode->getIdentifier(),
            ],
            'documents' => $documents,
            'documentCount' => count($documents),
        ];
    }

    /**
     * Resolve the site node to query.
     */
    private function resolveSiteNode(ContentContext $context, ?string $siteNodeName): ?NodeInterface
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
        NodeInterface $node,
        string $nodeTypeFilter,
        int $maxDepth,
        int $currentDepth,
        array &$documents
    ): void {
        // Check depth limit
        if ($maxDepth >= 0 && $currentDepth > $maxDepth) {
            return;
        }

        // Add current node if it matches the filter
        if ($node->getNodeType()->isOfType($nodeTypeFilter)) {
            $documents[] = $this->extractDocumentData($node, $currentDepth);
        }

        // Traverse child documents
        $childDocuments = $node->getChildNodes(self::DOCUMENT_TYPE);
        foreach ($childDocuments as $childNode) {
            $this->traverseDocuments($childNode, $nodeTypeFilter, $maxDepth, $currentDepth + 1, $documents);
        }
    }

    /**
     * Extract data from a single document node.
     */
    private function extractDocumentData(NodeInterface $node, int $depth): array
    {
        $childDocuments = $node->getChildNodes(self::DOCUMENT_TYPE);

        return [
            'identifier' => $node->getIdentifier(),
            'nodeType' => $node->getNodeType()->getName(),
            'path' => $node->getPath(),
            'depth' => $depth,
            'title' => $node->getProperty('title') ?? $node->getName(),
            'uriPath' => $node->getProperty('uriPathSegment') ?? '',
            'properties' => $this->extractSelectedProperties($node),
            'childDocumentCount' => count($childDocuments),
            'isHidden' => $node->isHidden(),
            'isHiddenInMenu' => (bool)$node->getProperty('_hiddenInIndex'),
        ];
    }

    /**
     * Extract only the configured properties from a node.
     */
    private function extractSelectedProperties(NodeInterface $node): array
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

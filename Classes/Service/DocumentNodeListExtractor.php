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
    use CreateContentContextTrait;
    use PropertyExtractionTrait;

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
     * Provide the configured list of node properties to extract for documents.
     *
     * If no configuration is set, defaults to ['title', 'metaDescription', 'uriPathSegment'].
     *
     * @return string[] Array of property names to include.
     */
    protected function getIncludedProperties(): array
    {
        return $this->includedProperties ?? ['title', 'metaDescription', 'uriPathSegment'];
    }

    /**
         * Extracts a structured list of document nodes for a site.
         *
         * Produces an associative array with metadata about the extraction and a list of documents discovered
         * starting from the resolved site node. Traversal respects the supplied node type filter and maximum depth.
         *
         * @param string $workspace Workspace name to build the content context for (default: 'live').
         * @param array $dimensions Content dimension values used when creating the content context.
         * @param string|null $siteNodeName Specific site node name to start from; when null the first available site is used.
         * @param string $nodeTypeFilter Only nodes of this node type will be included.
         * @param int $depth Maximum traversal depth where -1 means unlimited.
         * @return array{
         *     generatedAt: string,
         *     workspace: string,
         *     dimensions: array,
         *     site: array{name: string, nodeType: string, identifier: string},
         *     documents: array,
         *     documentCount: int
         * } Structure containing extraction metadata and the collected documents.
         *
         * @throws \InvalidArgumentException If no site node can be resolved.
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
     * Finds the site node to use as the traversal root.
     *
     * If $siteNodeName is provided, returns the node at /sites/{siteNodeName}. Otherwise,
     * returns the first child of /sites whose type matches the class SITE_TYPE constant,
     * falling back to the first child of /sites if no matching-type children exist.
     *
     * @param ContentContext $context The content context used to resolve nodes.
     * @param string|null $siteNodeName Optional explicit site node name to resolve.
     * @return NodeInterface|null The resolved site node, or `null` if no site node could be found.
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
         * Traverse a document subtree and collect data for nodes that match the provided node type filter.
         *
         * @param NodeInterface $node The root node to begin traversal from.
         * @param string $nodeTypeFilter Node type identifier to include in the results (e.g. self::DOCUMENT_TYPE).
         * @param int $maxDepth Maximum depth to traverse; use a negative value to allow unlimited depth.
         * @param int $currentDepth Current depth of the $node relative to the initial root (used for depth limiting and recorded in each document entry).
         * @param array &$documents Array that will be appended with associative document data generated by extractDocumentData().
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
     * Gather minimal metadata and selected properties for a document node.
     *
     * @param NodeInterface $node The document node to extract data from.
     * @param int $depth The node's depth relative to the traversal root.
     * @return array{
     *     identifier: string,
     *     nodeType: string,
     *     path: string,
     *     depth: int,
     *     title: string,
     *     uriPath: string,
     *     properties: array,
     *     childDocumentCount: int,
     *     isHidden: bool,
     *     isHiddenInMenu: bool
     * } Associative array with the extracted document data. */
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
            'isHiddenInMenu' => (bool)$node->isHiddenInIndex(),
        ];
    }

}
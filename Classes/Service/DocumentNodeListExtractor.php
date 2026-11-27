<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Routing\Exception\NoSiteException;
use NEOSidekick\AiAssistant\Exception\InvalidContentDimensionsException;
use NEOSidekick\AiAssistant\Service\Traits\NodeTimestampExtractionTrait;
use NEOSidekick\AiAssistant\Service\Traits\PropertyExtractionTrait;
use Psr\Log\LoggerInterface;

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
    use NodeTimestampExtractionTrait;

    private const DOCUMENT_TYPE = 'Neos.Neos:Document';
    private const SITE_TYPE = 'Neos.Neos:Site';
    private const SITES_PATH = '/sites';
    private const SORTABLE_FIELDS = ['creationDateTime', 'lastModificationDateTime', 'lastPublicationDateTime'];
    private const SORT_DIRECTIONS = ['asc', 'desc'];

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ContentDimensionResolver
     */
    protected $contentDimensionResolver;

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
     * @param array $dimensions Content dimensions as the caller named them; the result reports the ones the list was read in
     * @param string|null $siteNodeName Site node name (null = the site whose Domain record matches the request host, else Neos's default site)
     * @param string $nodeTypeFilter Filter by NodeType
     * @param int $depth Maximum traversal depth (-1 = unlimited)
     * @param string|null $requestHost Host of the incoming request, used when no site node name is given
     * @param bool $includeContentDimensions Also describe the content dimensions of this installation, for a caller that has to choose one
     * @param string|null $sortBy Timestamp to sort the documents by instead of tree order: creationDateTime, lastModificationDateTime or lastPublicationDateTime
     * @param string $sortDirection "desc" (newest first) or "asc"; only read together with $sortBy
     * @return array
     * @throws InvalidContentDimensionsException When the dimensions are not configured on this site
     * @throws \InvalidArgumentException When $sortBy or $sortDirection is not one of the allowed values
     */
    public function extract(
        string $workspace = 'live',
        array $dimensions = [],
        ?string $siteNodeName = null,
        string $nodeTypeFilter = self::DOCUMENT_TYPE,
        int $depth = -1,
        ?string $requestHost = null,
        bool $includeContentDimensions = false,
        ?string $sortBy = null,
        string $sortDirection = 'desc'
    ): array {
        $this->assertValidSorting($sortBy, $sortDirection);
        $dimensions = $this->contentDimensionResolver->resolve($dimensions);
        $context = $this->createContentContext($workspace, $dimensions);
        $availableSiteNodes = $this->findAvailableSiteNodes($context);
        $siteNode = $this->resolveSiteNode($context, $siteNodeName, $requestHost, $availableSiteNodes);

        if ($siteNode === null) {
            throw new \InvalidArgumentException('No site found', 1735660100);
        }

        $documents = [];
        $this->traverseDocuments($siteNode, $nodeTypeFilter, $depth, 0, $documents);
        if ($sortBy !== null) {
            $documents = $this->sortDocuments($documents, $sortBy, $sortDirection);
        }

        return [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'workspace' => $workspace,
            'dimensions' => $dimensions,
            'site' => [
                'name' => $siteNode->getName(),
                'nodeType' => $siteNode->getNodeType()->getName(),
                'identifier' => $siteNode->getIdentifier(),
            ],
            'availableSites' => $this->describeSiteNodes($availableSiteNodes),
            ...($includeContentDimensions ? ['contentDimensions' => $this->contentDimensionResolver->describeConfiguration()] : []),
            ...($sortBy !== null ? ['sortBy' => $sortBy, 'sortDirection' => $sortDirection] : []),
            'documents' => $documents,
            'documentCount' => count($documents),
        ];
    }

    /**
     * Resolve the site node to query: an explicitly requested site, else the site whose active
     * Domain record matches the request host (Neos's suffix matching, site state ignored), else
     * Neos's default site (`Neos.Neos.defaultSiteNodeName`, else the first online site); only
     * when Neos has no default either, the first site under `/sites`, logged.
     *
     * @param array<int, NodeInterface> $availableSiteNodes
     * @throws \InvalidArgumentException If a site node name is given that is not a site node name or does not exist
     */
    private function resolveSiteNode(
        ContentContext $context,
        ?string $siteNodeName,
        ?string $requestHost,
        array $availableSiteNodes
    ): ?NodeInterface {
        if ($siteNodeName !== null) {
            $normalizedSiteNodeName = strtolower($siteNodeName);
            $siteNode = preg_match(NodeInterface::MATCH_PATTERN_NAME, $normalizedSiteNodeName) === 1
                ? $context->getNode(self::SITES_PATH . '/' . $normalizedSiteNodeName)
                : null;
            if ($siteNode === null) {
                throw new \InvalidArgumentException(sprintf(
                    'No site "%s" found. Available sites: %s',
                    $siteNodeName,
                    implode(', ', array_map(static fn(NodeInterface $node): string => $node->getName(), $availableSiteNodes))
                ), 1735660100);
            }

            return $siteNode;
        }

        $hostSiteNode = $this->resolveSiteNodeByHost($context, $requestHost);
        if ($hostSiteNode !== null) {
            return $hostSiteNode;
        }

        $this->logger->info(sprintf(
            'NEOSidekick document list: no site matches the request host "%s", falling back to the first site of the installation.',
            $requestHost ?? ''
        ), ['requestHost' => $requestHost]);

        return $availableSiteNodes[0] ?? null;
    }

    /**
     * The node of the site whose active Domain record matches the given host, else Neos's default
     * site (`Neos.Neos.defaultSiteNodeName`, else the first online site). Null when there is no
     * host at all or when Neos has no default site either.
     */
    private function resolveSiteNodeByHost(ContentContext $context, ?string $requestHost): ?NodeInterface
    {
        if ($requestHost === null) {
            return null;
        }

        try {
            $site = $this->siteService->getSiteByHostName($requestHost);
        } catch (NoSiteException $exception) {
            return null;
        }

        return $context->getNode(self::SITES_PATH . '/' . $site->getNodeName());
    }

    /**
     * All site nodes of this installation, in tree order.
     *
     * @return array<int, NodeInterface>
     */
    private function findAvailableSiteNodes(ContentContext $context): array
    {
        $sitesNode = $context->getNode(self::SITES_PATH);
        if ($sitesNode === null) {
            return [];
        }

        $siteNodes = $sitesNode->getChildNodes(self::SITE_TYPE);
        if (empty($siteNodes)) {
            $siteNodes = $sitesNode->getChildNodes();
        }

        return array_values($siteNodes);
    }

    /**
     * @param array<int, NodeInterface> $siteNodes
     * @return array<int, array{nodeName: string, name: string}>
     */
    private function describeSiteNodes(array $siteNodes): array
    {
        return array_map(static fn(NodeInterface $node): array => [
            'nodeName' => $node->getName(),
            'name' => (string)($node->getProperty('title') ?? $node->getName()),
        ], $siteNodes);
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
            'isHiddenInMenu' => (bool)$node->isHiddenInIndex(),
            ...$this->extractTimestamps($node),
        ];
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidSorting(?string $sortBy, string $sortDirection): void
    {
        if ($sortBy === null) {
            return;
        }

        if (!in_array($sortBy, self::SORTABLE_FIELDS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot sort by "%s". Allowed values for sortBy: %s',
                $sortBy,
                implode(', ', self::SORTABLE_FIELDS)
            ), 1795766400);
        }

        if (!in_array($sortDirection, self::SORT_DIRECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown sortDirection "%s". Allowed values: %s',
                $sortDirection,
                implode(', ', self::SORT_DIRECTIONS)
            ), 1795766401);
        }
    }

    /**
     * Sorts the flat document list by one of its timestamps. Documents without a value (never
     * published) come last in both directions; documents with the same value keep tree order.
     *
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function sortDocuments(array $documents, string $sortBy, string $sortDirection): array
    {
        $factor = $sortDirection === 'asc' ? 1 : -1;

        usort($documents, static function (array $left, array $right) use ($sortBy, $factor): int {
            $leftValue = $left[$sortBy];
            $rightValue = $right[$sortBy];

            if ($leftValue === null || $rightValue === null) {
                return ($leftValue === null) <=> ($rightValue === null);
            }

            // Compared as points in time: the offsets of two ISO strings differ across a DST change.
            return $factor * (strtotime($leftValue) <=> strtotime($rightValue));
        });

        return $documents;
    }
}

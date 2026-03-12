<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\DocumentNodeListExtractor;
use NEOSidekick\AiAssistant\Service\SearchNodesExtractor;

/**
 * API controller to search nodes across the content repository.
 *
 * Provides grep-like search functionality across all node properties
 * for a given workspace and dimension configuration. Used by LLM agents
 * to find specific content.
 *
 * Authentication is done via JWT Bearer token (Flow security provider).
 *
 * @noinspection PhpUnused
 */
class SearchNodesApiController extends ActionController
{
    private const DOCUMENT_TYPE = 'Neos.Neos:Document';

    /**
     * @Flow\Inject
     * @var SearchNodesExtractor
     */
    protected $extractor;

    /**
     * @Flow\Inject
     * @var DocumentNodeListExtractor
     */
    protected $documentNodeListExtractor;

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * Initialize action - set JSON content type.
     */
    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * Search nodes by property values or identifier.
     *
     * Performs a case-insensitive search across all node properties,
     * with additional support for direct identifier lookup. Returns matching nodes with their
     * properties and parent document context.
     *
     * If query is empty or "*", returns all document nodes using the same
     * extraction behavior as DocumentNodeListApiController.
     *
     * @param string $query The search term or node identifier (empty or "*" returns all document nodes)
     * @param string $workspace The workspace name (default: 'live')
     * @param string $dimensions JSON-encoded dimensions array
     * @param string $nodeTypeFilter Filter by NodeType (e.g., 'Neos.Neos:Content')
     * @param string $pathStartingPoint Limit search to nodes under this path
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function searchAction(
        string $query = '',
        string $workspace = 'live',
        string $dimensions = '{}',
        string $nodeTypeFilter = '',
        string $pathStartingPoint = ''
    ): string {
        // Parse dimensions from JSON string
        $dimensionsArray = json_decode($dimensions, true);
        if (!is_array($dimensionsArray)) {
            $dimensionsArray = [];
        }

        $normalizedQuery = trim($query);

        // Empty query and wildcard query return a full document list.
        if ($normalizedQuery === '' || $normalizedQuery === '*') {
            try {
                $result = $this->documentNodeListExtractor->extract(
                    workspace: $workspace,
                    dimensions: $dimensionsArray,
                    siteNodeName: null,
                    nodeTypeFilter: self::DOCUMENT_TYPE,
                    depth: -1
                );

                if ($pathStartingPoint !== '') {
                    $documents = array_values(array_filter(
                        $result['documents'],
                        static fn(array $document): bool => isset($document['path'])
                            && str_starts_with((string)$document['path'], $pathStartingPoint)
                    ));
                    $result['documents'] = $documents;
                    $result['documentCount'] = count($documents);
                }

                return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            } catch (\Exception $e) {
                $this->response->setStatusCode(400);
                return json_encode([
                    'error' => 'Bad Request',
                    'message' => $e->getMessage()
                ], JSON_THROW_ON_ERROR);
            }
        }

        try {
            $result = $this->extractor->search(
                query: $normalizedQuery,
                workspace: $workspace,
                dimensions: $dimensionsArray,
                nodeTypeFilter: $nodeTypeFilter !== '' ? $nodeTypeFilter : null,
                pathStartingPoint: $pathStartingPoint !== '' ? $pathStartingPoint : null
            );

            return json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            $this->response->setStatusCode(400);
            return json_encode([
                'error' => 'Bad Request',
                'message' => $e->getMessage()
            ], JSON_THROW_ON_ERROR);
        }
    }

}

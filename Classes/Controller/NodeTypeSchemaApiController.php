<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Service\NodeTypeSchemaExtractor;

/**
 * API controller to expose NodeType schema data for external consumption.
 *
 * This controller is placed directly in the Controller namespace (without subpackage)
 * to work around Flow routing issues with subpackages.
 *
 * Authentication is done via JWT Bearer token (Flow security provider).
 *
 * @noinspection PhpUnused
 */
class NodeTypeSchemaApiController extends ActionController
{
    /**
     * @Flow\Inject
     * @var NodeTypeSchemaExtractor
     */
    protected $schemaExtractor;

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
     * Get the complete NodeType schema as JSON.
     *
     * @param bool $includeAbstract Include abstract NodeTypes in output
     * @param string $filter Filter by NodeType prefix (e.g., "CodeQ.Site:")
     * @return string JSON response
     * @throws JsonException
     * @Flow\SkipCsrfProtection
     */
    public function getNodeTypeSchemaAction(bool $includeAbstract = false, string $filter = ''): string
    {
        $schema = $this->schemaExtractor->extract($includeAbstract, $filter);

        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

}

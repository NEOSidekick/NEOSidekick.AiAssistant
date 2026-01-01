<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use JsonException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use NEOSidekick\AiAssistant\Controller\Trait\ApiAuthenticationTrait;
use NEOSidekick\AiAssistant\Service\NodeTypeSchemaExtractor;

/**
 * API controller to expose NodeType schema data for external consumption.
 *
 * This controller is placed directly in the Controller namespace (without subpackage)
 * to work around Flow routing issues with subpackages.
 *
 * @noinspection PhpUnused
 */
class NodeTypeSchemaApiController extends ActionController
{
    use ApiAuthenticationTrait;
    /**
     * @Flow\Inject
     * @var NodeTypeSchemaExtractor
     */
    protected $schemaExtractor;

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @var string[]
     */
    protected $supportedMediaTypes = ['application/json'];

    /**
     * Set the HTTP response Content-Type header to "application/json" for this controller's responses.
     */
    public function initializeAction(): void
    {
        $this->response->setContentType('application/json');
    }

    /**
     * Return the NodeType schema encoded as JSON.
     *
     * @param bool $includeAbstract Whether to include abstract NodeTypes.
     * @param string $filter NodeType prefix to filter by (e.g., "CodeQ.Site:"); empty string for no filtering.
     * @return string JSON-encoded NodeType schema.
     * @throws JsonException If JSON encoding fails.
     * @Flow\SkipCsrfProtection
     */
    public function getNodeTypeSchemaAction(bool $includeAbstract = false, string $filter = ''): string
    {
        // Validate Bearer token authentication
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        $schema = $this->schemaExtractor->extract($includeAbstract, $filter);

        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

}
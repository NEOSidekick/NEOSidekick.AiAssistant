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
        // Validate Bearer token authentication
        $authError = $this->validateAuthentication();
        if ($authError !== null) {
            return $authError;
        }

        $schema = $this->schemaExtractor->extract($includeAbstract, $filter);

        return json_encode($schema, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

}

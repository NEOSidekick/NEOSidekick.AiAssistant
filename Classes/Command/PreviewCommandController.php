<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use NEOSidekick\AiAssistant\Service\PreviewTokenService;

/**
 * CLI commands around the signed preview URLs (see PreviewTokenService).
 *
 * Mainly useful for testing: generates a valid public preview URL without
 * needing a JWT for the GetPreviewApi endpoint.
 *
 * @Flow\Scope("singleton")
 */
class PreviewCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var PreviewTokenService
     */
    protected $previewTokenService;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="http.baseUri")
     * @var string|null
     */
    protected $configuredBaseUri;

    /**
     * Generate a signed preview URL for a document node
     *
     * Example: ./flow preview:generateurl --node-id 8e5eebb1-c9ep-4c66-9bde-4d7674fa9b1e --workspace user-admin
     *
     * @param string $nodeId Node aggregate identifier of the document node
     * @param string $workspace Workspace name to render the node in (default: live)
     * @param string $baseUri Base URI to prepend to the preview path (default: configured Neos.Flow http.baseUri)
     * @param string $dimensions JSON-encoded dimensions array, e.g. {"language":["de"]}
     * @param int $ttl Token lifetime in seconds (default: 900 = 15 minutes)
     */
    public function generateUrlCommand(
        string $nodeId,
        string $workspace = 'live',
        string $baseUri = '',
        string $dimensions = '{}',
        int $ttl = PreviewTokenService::TOKEN_TTL
    ): void {
        $tokenData = $this->previewTokenService->generatePreviewPath($nodeId, $workspace, $dimensions, $ttl);

        $effectiveBaseUri = $baseUri !== '' ? $baseUri : (string)($this->configuredBaseUri ?? '');
        if ($effectiveBaseUri === '') {
            $this->outputLine('<comment>No --base-uri given and Neos.Flow.http.baseUri is not configured - printing the relative path.</comment>');
        }

        $this->outputLine(rtrim($effectiveBaseUri, '/') . $tokenData['previewPath']);
        $this->outputLine('<info>Expires at: %s</info>', [$tokenData['expiresAt']->format(DATE_ATOM)]);
    }
}

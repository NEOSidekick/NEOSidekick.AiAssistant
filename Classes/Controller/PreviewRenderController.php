<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Service\SiteService;
use Neos\Neos\View\FusionView;
use NEOSidekick\AiAssistant\Service\PreviewTokenService;
use Psr\Http\Message\ResponseInterface;

/**
 * Public (unauthenticated) endpoint that renders the full frontend HTML of
 * a document node in an arbitrary workspace, protected by a signed,
 * short-lived HMAC token (see PreviewTokenService).
 *
 * This is used by external screenshot services (e.g. Browserless) that
 * cannot authenticate against the Neos backend but need to render a page
 * in a user's personal workspace.
 *
 * Security notes:
 * - This controller is intentionally NOT matched by any authentication
 *   provider request pattern (see Settings.Internal.yaml) - the HMAC token
 *   is the only access control.
 * - Neos normally guards non-live rendering behind backend authentication
 *   (in the routing/NodeConverter layer). We bypass this by building the
 *   content context ourselves and rendering inside
 *   Context::withoutAuthorizationChecks() so node/entity privileges do not
 *   require an authenticated session.
 *
 * @noinspection PhpUnused
 */
class PreviewRenderController extends ActionController
{
    /**
     * @var string
     */
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var FusionView
     */
    protected $view;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     * @var PreviewTokenService
     */
    protected $previewTokenService;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;
    #[\Neos\Flow\Annotations\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * Render the full frontend HTML for the given node, like the frontend would.
     *
     * @param string $nodeId Node aggregate identifier of the document node
     * @param string $workspace Workspace name to render the node in
     * @param string $dimensions JSON-encoded dimensions array
     * @param int $expires Unix timestamp until which the token is valid
     * @param string $token HMAC token over nodeId|workspace|dimensions|expires
     * @return string The rendered HTML page (or a plain-text error)
     * @Flow\SkipCsrfProtection
     */
    public function indexAction(
        string $nodeId = '',
        string $workspace = 'live',
        string $dimensions = '{}',
        int $expires = 0,
        string $token = ''
    ): string {
        if (!$this->previewTokenService->isTokenValid($nodeId, $workspace, $dimensions, $expires, $token)) {
            $this->response->setStatusCode(403);
            $this->response->setContentType('text/plain');
            return 'Invalid or expired preview token';
        }

        $dimensionsArray = json_decode($dimensions, true);
        if (!is_array($dimensionsArray)) {
            $dimensionsArray = [];
        }

        // Fetching nodes in a non-live workspace can be subject to node/entity
        // privileges (e.g. Neos.Neos:Backend.OtherUsersPersonalWorkspaceAccess
        // on Context::validateWorkspace()) - resolve the node without
        // authorization checks, as the HMAC token already authorizes this
        // request. Note: withoutAuthorizationChecks() discards the closure's
        // return value in Flow 8, so we capture results by reference.
        $node = null;
        $this->securityContext->withoutAuthorizationChecks(function () use (&$node, $nodeId, $workspace, $dimensionsArray): void {
            $node = $this->findNode($nodeId, $workspace, $dimensionsArray);
        });

        if ($node === null) {
            $this->response->setStatusCode(404);
            $this->response->setContentType('text/plain');
            return 'Node not found';
        }

        // Render exactly like Neos' own Frontend\NodeController does
        $this->view->assign('value', $node);

        // Neos only renders non-live workspaces for authenticated backend
        // users; out-of-session rendering works because we already have the
        // node in a self-built context and disable authorization checks for
        // anything the Fusion rendering touches.
        $output = null;
        $this->securityContext->withoutAuthorizationChecks(function () use (&$output): void {
            $output = $this->view->render();
        });

        // Neos.Neos:Page is a Neos.Fusion:Http.Message, so FusionView may
        // return a full PSR-7 response instead of a plain string.
        if ($output instanceof ResponseInterface) {
            $this->response->setStatusCode($output->getStatusCode());
            foreach ($output->getHeaders() as $headerName => $headerValues) {
                foreach ($headerValues as $headerValue) {
                    $this->response->setHttpHeader($headerName, $headerValue);
                }
            }
            return (string)$output->getBody();
        }

        return (string)$output;
    }

    /**
     * Find the document node by aggregate identifier in the given workspace
     * and dimensions.
     *
     * The context is (re-)created with the Site matching the node's path so
     * that FusionView can resolve the correct site node and Fusion setup
     * even without a domain-based request match (multi-site safe).
     */
    protected function findNode(string $nodeId, string $workspace, array $dimensionsArray): ?\Neos\ContentRepository\Core\Projection\ContentGraph\Node
    {
        if ($dimensionsArray === []) {
            $dimensionsArray = $this->getDefaultDimensions();
        }

        // TODO 9.0 migration: !! CreateContentContextTrait::createContentContext() is removed in Neos 9.0.
        $context = $this->createContentContext($workspace, $dimensionsArray);
        $node = $context->getNodeByIdentifier($nodeId);

        if ($node === null) {
            return null;
        }

        $site = $this->findSiteForNode($node);
        if ($site === null) {
            return $node;
        }

        $contextProperties = $node->getContext()->getProperties();
        $contextProperties['currentSite'] = $site;
        if ($domain = $site->getFirstActiveDomain()) {
            $contextProperties['currentDomain'] = $domain;
        }
        $siteContext = $this->_contextFactory->create($contextProperties);

        return $siteContext->getNodeByIdentifier($nodeId);
    }

    /**
     * Fall back to the default preset values of all configured content
     * dimensions, so preview URLs without explicit dimensions work on
     * dimension-enabled sites.
     *
     * @return array<string, array<string>>
     */
    protected function getDefaultDimensions(): array
    {
        $dimensions = [];
        foreach ($this->contentDimensionPresetSource->getAllPresets() as $dimensionName => $dimensionConfiguration) {
            $defaultPreset = $dimensionConfiguration['presets'][$dimensionConfiguration['defaultPreset']] ?? null;
            if (is_array($defaultPreset) && isset($defaultPreset['values'])) {
                $dimensions[$dimensionName] = $defaultPreset['values'];
            }
        }

        return $dimensions;
    }

    /**
     * Determine the Site the given node belongs to (via its node path).
     */
    protected function findSiteForNode(\Neos\ContentRepository\Core\Projection\ContentGraph\Node $node): ?Site
    {
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        // TODO 9.0 migration: Try to remove the (string) cast and make your code more type-safe.
        if (!str_starts_with((string) $subgraph->findNodePath($node->aggregateId), SiteService::SITES_ROOT_PATH . '/')) {
            return null;
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);

        // TODO 9.0 migration: Try to remove the (string) cast and make your code more type-safe.
        $sitePath = NodePaths::getRelativePathBetween(SiteService::SITES_ROOT_PATH, (string) $subgraph->findNodePath($node->aggregateId));
        $siteNodeName = explode('/', $sitePath)[0];

        return $this->_siteRepository->findOneByNodeName($siteNodeName);
    }
}

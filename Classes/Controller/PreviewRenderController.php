<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context;
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
 *   (the ContentRepositoryAuthProvider denies reading other workspaces for
 *   anonymous requests). We bypass this by resolving the node and rendering
 *   inside Context::withoutAuthorizationChecks() so workspace read
 *   privileges do not require an authenticated session.
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

    #[\Neos\Flow\Annotations\Inject]
    protected \NEOSidekick\AiAssistant\Service\ContentRepositoryProvider $contentRepositoryProvider;

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

        // Fetching nodes in a non-live workspace is denied for anonymous
        // requests by the ContentRepositoryAuthProvider - resolve the node
        // without authorization checks, as the HMAC token already authorizes
        // this request. Note: withoutAuthorizationChecks() discards the
        // closure's return value in Flow, so we capture results by reference.
        $node = null;
        $this->securityContext->withoutAuthorizationChecks(function () use (&$node, $nodeId, $workspace, $dimensionsArray): void {
            $node = $this->findNode($nodeId, $workspace, $dimensionsArray);
        });

        if ($node === null) {
            $this->response->setStatusCode(404);
            $this->response->setContentType('text/plain');
            return 'Node not found';
        }

        // Render exactly like Neos' own Frontend\NodeController does.
        // The Neos 9 FusionView resolves the site node and Site itself via
        // the closest Neos.Neos:Site ancestor (multi-site safe), so no
        // site-specific context needs to be built here anymore.
        $this->view->assign('value', $node);
        $this->view->assign('request', $this->request);

        // Neos only renders non-live workspaces for authenticated backend
        // users; out-of-session rendering works because we already resolved
        // the node and disable authorization checks for anything the Fusion
        // rendering touches.
        $output = null;
        $this->securityContext->withoutAuthorizationChecks(function () use (&$output): void {
            $output = $this->view->render();
        });

        // Neos.Neos:Page is a Neos.Fusion:Http.Message, so FusionView may
        // return a full PSR-7 response instead of a plain stream.
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
     * Must run inside Context::withoutAuthorizationChecks() so that the
     * ContentRepositoryAuthProvider grants read access to the workspace.
     */
    protected function findNode(string $nodeId, string $workspace, array $dimensionsArray): ?Node
    {
        $contentRepository = $this->contentRepositoryProvider->getContentRepository();

        try {
            if ($dimensionsArray === []) {
                $dimensionSpacePoint = $this->getDefaultDimensionSpacePoint($contentRepository);
            } else {
                $dimensionSpacePoint = DimensionSpacePoint::fromArray($this->dimensionCoordinatesFromLegacyDimensionsArray($dimensionsArray));
            }
            $subgraph = $contentRepository->getContentSubgraph(WorkspaceName::fromString($workspace), $dimensionSpacePoint);
            return $subgraph->findNodeById(NodeAggregateId::fromString($nodeId));
        } catch (\InvalidArgumentException | \Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist $e) {
            return null;
        }
    }

    /**
     * Preview URLs historically carry dimensions in the legacy context format
     * (['language' => ['de', 'en']] with fallback chains). A DimensionSpacePoint
     * has exactly one coordinate per dimension, so we use the first (primary)
     * value of each fallback chain; plain scalar values are accepted as-is.
     *
     * @param array<string, mixed> $dimensionsArray
     * @return array<string, string>
     */
    protected function dimensionCoordinatesFromLegacyDimensionsArray(array $dimensionsArray): array
    {
        $coordinates = [];
        foreach ($dimensionsArray as $dimensionName => $dimensionValues) {
            if (is_array($dimensionValues)) {
                $firstValue = reset($dimensionValues);
                if (is_string($firstValue)) {
                    $coordinates[$dimensionName] = $firstValue;
                }
            } elseif (is_string($dimensionValues)) {
                $coordinates[$dimensionName] = $dimensionValues;
            }
        }

        return $coordinates;
    }

    /**
     * Fall back to a default dimension space point (the first root
     * generalization of the content repository's variation graph, like
     * Neos' own FusionExceptionView does), so preview URLs without explicit
     * dimensions work on dimension-enabled sites.
     */
    protected function getDefaultDimensionSpacePoint(\Neos\ContentRepository\Core\ContentRepository $contentRepository): DimensionSpacePoint
    {
        $rootGeneralizations = $contentRepository->getVariationGraph()->getRootGeneralizations();
        $firstRootGeneralization = reset($rootGeneralizations);

        return $firstRootGeneralization instanceof DimensionSpacePoint
            ? $firstRootGeneralization
            : DimensionSpacePoint::createWithoutDimensions();
    }
}

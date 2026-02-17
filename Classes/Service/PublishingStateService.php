<?php

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequestFactory;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Domain\Service\AutomationsConfigurationService;
use NEOSidekick\AiAssistant\Domain\Service\AutomationRulesService;
use NEOSidekick\AiAssistant\Dto\PublishingState;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Security\JwtTokenFactory;
use Psr\Log\LoggerInterface;

/**
 * Service for managing state during the publishing process
 *
 * @Flow\Scope("singleton")
 */
class PublishingStateService
{
    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @Flow\Inject
     * @var ApiFacade
     */
    protected $apiFacade;

    /**
     * @Flow\Inject
     * @var AutomationsConfigurationService
     */
    protected $automationsConfigurationService;

    /**
     * @Flow\Inject
     * @var AutomationRulesService
     */
    protected $automationRulesService;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @var PublishingState
     */
    protected $publishingState;


    /**
     * @Flow\Inject
     * @var ActionRequestFactory
     */
    protected $actionRequestFactory;


    /**
     * @Flow\Inject
     * @var JwtTokenFactory
     */
    protected $jwtTokenFactory;

    /**
     * @Flow\Inject
     * @var UriBuilder
     */
    protected $uriBuilder;

    /**
     * Whether the UriBuilder has been configured with a valid host for generating absolute URIs.
     */
    private bool $uriBuilderInitialized = false;

    /**
     * Configure the UriBuilder with the given ServerRequest for generating absolute URIs.
     */
    private function configureUriBuilder(ServerRequest $serverRequest): void
    {
        $parameters = $serverRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS)
            ?? RouteParameters::createEmpty();
        $serverRequest = $serverRequest->withAttribute(
            ServerRequestAttributes::ROUTING_PARAMETERS,
            $parameters->withParameter('requestUriHost', $serverRequest->getUri()->getHost())
        );
        $actionRequest = $this->actionRequestFactory->createActionRequest($serverRequest);
        $this->uriBuilder->setRequest($actionRequest);
        $this->uriBuilder->setCreateAbsoluteUri(true);
        $this->uriBuilderInitialized = true;
    }

    /**
     * Configure the UriBuilder for a site. Uses the site's primary domain, or falls back to HTTP request host
     * when no domain is configured (development environments).
     */
    private function configureUriBuilderForSite(Site $site): void
    {
        $this->uriBuilderInitialized = false;

        $serverRequest = $this->buildServerRequestForSite($site);
        if ($serverRequest === null) {
            $this->systemLogger->warning(
                'Cannot configure URI builder for site "' . $site->getName() . '": no active domain and no HTTP context',
                ['packageKey' => 'NEOSidekick.AiAssistant']
            );
            return;
        }

        $this->configureUriBuilder($serverRequest);
    }

    /**
     * Build a ServerRequest for the given site: prefer the site's primary domain,
     * fall back to the current HTTP request host (for development environments without domain config).
     */
    private function buildServerRequestForSite(Site $site): ?ServerRequest
    {
        /**
         * @var Domain|null
         */
        $primaryDomain = $site->getPrimaryDomain();
        if ($primaryDomain !== null) {
            $uri = (new Uri())
                ->withScheme($primaryDomain->getScheme() ?: 'https')
                ->withHost($primaryDomain->getHostname());
            $port = $primaryDomain->getPort();
            if ($port !== null && !in_array($port, [80, 443], true)) {
                $uri = $uri->withPort($port);
            }

            return new ServerRequest('GET', $uri);
        }

        // Fallback: use HTTP request host (development environments without domain config)
        $serverRequest = ServerRequest::fromGlobals();
        if (!empty($serverRequest->getUri()->getHost())) {
            $this->systemLogger->info(
                'Site "' . $site->getName() . '" has no active domain, falling back to HTTP request host',
                ['packageKey' => 'NEOSidekick.AiAssistant']
            );
            return $serverRequest;
        }

        return null;
    }

    /**
     * Initialize the publishing state
     */
    public function initializeObject(): void
    {
        $this->publishingState = new PublishingState();
    }

    /**
     * Get the current publishing state
     *
     * @return PublishingState
     */
    public function getPublishingState(): PublishingState
    {
        return $this->publishingState;
    }

    /**
     * Called at the end of this object's lifecycle.
     * We'll build a single batch request containing all documents that had changes.
     */
    public function shutdownObject(): void
    {
        if (!$this->publishingState->hasDocumentChangeSets()) {
            return;
        }

        if (defined('FLOW_SAPITYPE') && FLOW_SAPITYPE === 'CLI') {
            return;
        }

        try {
            $finalRequests = [];
            $moduleToPropertyMapping = [
                'focus_keyword_generator' => 'focusKeyword',
                'seo_title' => 'titleOverride',
                'meta_description' => 'metaDescription',
            ];

            // The document node data may still reference the source workspace in context paths.
            // Fix them to use the target workspace before sending to the external service.
            $this->fixWorkspaceReferencesForExternalService();

            $this->systemLogger->debug('Publishing Data (before sending):', $this->publishingState->toArray());

            $lastConfiguredSiteNodeName = null;

            // Iterate over all document nodes in publishingState
            foreach ($this->publishingState->getDocumentChangeSets() as $documentPath => $documentChangeSet) {
                $documentNode = $documentChangeSet->getDocumentNode();

                if ($documentNode === null) {
                    $this->systemLogger->warning('Document node data missing for path: ' . $documentPath, [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                    continue;
                }

                // Extract site node name from the context path (e.g. "/sites/mysite/some/page@live" -> "mysite")
                $contextPathSegments = NodePaths::explodeContextPath($documentNode['nodeContextPath']);
                $nodePath = $contextPathSegments['nodePath'];
                $siteNodeName = NodePaths::isSubPathOf('/sites/', $nodePath)
                    ? explode('/', NodePaths::getRelativePathBetween('/sites', $nodePath))[0]
                    : null;

                if ($siteNodeName === null || $siteNodeName === '') {
                    $this->systemLogger->warning('Could not determine site from document path: ' . $nodePath, [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                    continue;
                }

                $site = $this->siteRepository->findOneByNodeName($siteNodeName);
                if (!$site) {
                    $this->systemLogger->warning('Could not find site with node name: ' . $siteNodeName, [
                        'packageKey' => 'NEOSidekick.AiAssistant'
                    ]);
                    continue;
                }

                // Reconfigure UriBuilder when site changes (ensures preview URLs use correct domain per-site)
                if ($siteNodeName !== $lastConfiguredSiteNodeName) {
                    $this->configureUriBuilderForSite($site);
                    $lastConfiguredSiteNodeName = $siteNodeName;
                }

                if (!$this->uriBuilderInitialized) {
                    continue;
                }

                // Get the active automation configuration for this site
                $automationConfig = $this->automationsConfigurationService->getActiveForSite($site);

                // Determine which modules to call using the AutomationRulesService
                $modulesToCall = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationConfig);

                $this->systemLogger->debug('Processing document node:', [
                    'path' => $documentPath,
                    'documentNode' => $documentNode,
                    'modulesToCall' => $modulesToCall
                ]);

                // If there are modules to call for this document, generate the necessary tokens and URLs
                if (!empty($modulesToCall)) {
                    $readOnlyToken = $this->jwtTokenFactory->createReadOnlyPreviewToken();
                    $previewUrl = $this->uriBuilder->uriFor(
                        'preview',
                        ['node' => $documentNode['nodeContextPath']],
                        'Preview',
                        'NEOSidekick.AiAssistant'
                    );

                    // Create individual requests for each module
                    foreach ($modulesToCall as $module) {
                        $propertyName = $moduleToPropertyMapping[$module] ?? null;
                        if (!$propertyName) {
                            continue;
                        }

                        $finalRequests[] = [
                            'module' => $module,
                            'user_input' => [
                                [
                                    'identifier' => 'url',
                                    // We append a timestamp here
                                    // to generate a unique URL
                                    // that will always bypass
                                    // a potential Nginx cache
                                    'value' => $previewUrl . '&token=' . $readOnlyToken . '&timestamp=' . time(),
                                ],
                                [
                                    'identifier' => 'title',
                                    'value' => $documentNode['properties']['title'],
                                ]
                            ],
                            'request_id' => $documentNode['nodeContextPath'] . '#' . $propertyName,
                        ];
                    }
                }
            }

            // If we have requests to send, construct and dispatch the final payload
            if (!empty($finalRequests) && $this->uriBuilderInitialized) {
                $this->uriBuilder->setFormat('json');
                $webhookUrl = $this->uriBuilder->uriFor(
                    'processSidekickResponse',
                    [],
                    'Webhook',
                    'NEOSidekick.AiAssistant'
                );
                $this->uriBuilder->reset();
                $writeToken = $this->jwtTokenFactory->createWriteAccessToken();
                $webhookAuthorizationHeader = 'Bearer ' . $writeToken;

                $this->systemLogger->debug('Sending batch request:', [
                    'requests' => $finalRequests,
                    'webhook_url' => $webhookUrl,
                    'webhookAuthorizationHeader' => $webhookAuthorizationHeader,
                ]);

                $this->apiFacade->sendBatchModuleRequest($finalRequests, $webhookUrl, $webhookAuthorizationHeader);

                $this->systemLogger->debug('Publishing Data (before cleanup):', $this->publishingState->toArray());
            } elseif (!empty($finalRequests) && !$this->uriBuilderInitialized) {
                $this->systemLogger->warning(
                    'Cannot build webhook URL: no valid domain context. Skipping batch module request.',
                    ['packageKey' => 'NEOSidekick.AiAssistant']
                );
            } else {
                $this->systemLogger->debug('No requests to send.');
            }
        } catch (\Throwable $e) {
            $this->systemLogger->error('Publishing state shutdown failed: ' . $e->getMessage(), [
                'packageKey' => 'NEOSidekick.AiAssistant',
                'exception' => $e
            ]);
        } finally {
            $this->publishingState = new PublishingState();
        }
    }

    /**
     * Fix workspace references in DocumentChangeSets before sending to the external service.
     *
     * During publishing, nodes retain their source workspace context (e.g. @user-admin)
     * even though they are being published to the target workspace (e.g. live).
     * This method corrects the nodeContextPath and any URIs that embed context paths
     * so the external service and webhook use the correct target workspace.
     */
    private function fixWorkspaceReferencesForExternalService(): void
    {
        $targetWorkspace = $this->publishingState->getWorkspaceName();

        foreach ($this->publishingState->getDocumentChangeSets() as $documentChangeSet) {
            $documentNode = $documentChangeSet->getDocumentNode();

            if (empty($documentNode) || empty($documentNode['nodeContextPath'])) {
                continue;
            }

            $contextPathSegments = NodePaths::explodeContextPath($documentNode['nodeContextPath']);
            if ($contextPathSegments['workspaceName'] === $targetWorkspace) {
                continue;
            }

            // Fix the nodeContextPath
            $originalContextPath = $documentNode['nodeContextPath'];
            $correctedContextPath = NodePaths::generateContextPath(
                $contextPathSegments['nodePath'],
                $targetWorkspace,
                $contextPathSegments['dimensions']
            );
            $documentNode['nodeContextPath'] = $correctedContextPath;

            // Fix URIs that embed context paths (e.g. preview-style URLs with __contextNodePath)
            $encodedOriginalContextPath = urlencode($originalContextPath);
            $encodedCorrectedContextPath = urlencode($correctedContextPath);
            foreach (['publicUri', 'previewUri'] as $uriField) {
                if (!empty($documentNode[$uriField]) && strpos($documentNode[$uriField], 'contextNodePath') !== false) {
                    $documentNode[$uriField] = str_replace($encodedOriginalContextPath, $encodedCorrectedContextPath, $documentNode[$uriField]);
                }
            }

            $documentChangeSet->setDocumentNode($documentNode);
        }
    }
}

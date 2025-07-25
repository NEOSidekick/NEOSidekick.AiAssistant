<?php

namespace NEOSidekick\AiAssistant\Service;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequestFactory;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Domain\Service\AutomationsConfigurationService;
use NEOSidekick\AiAssistant\Dto\ContentChangeDto;
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
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @var PublishingState
     */
    protected $publishingState;

    /**
     * @Flow\InjectConfiguration(path="webhooks.endpoints")
     * @var array
     */
    protected $endpoints = [];

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
     * Initialize the publishing state
     */
    public function initializeObject(): void
    {
        $this->publishingState = new PublishingState();
        $serverRequest = ServerRequest::fromGlobals();
        $parameters = $serverRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $serverRequest = $serverRequest->withAttribute(ServerRequestAttributes::ROUTING_PARAMETERS, $parameters->withParameter('requestUriHost', $serverRequest->getUri()->getHost()));
        $actionRequest = $this->actionRequestFactory->createActionRequest($serverRequest);
        $this->uriBuilder->setRequest($actionRequest);
        $this->uriBuilder->setCreateAbsoluteUri(true);
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
     *
     * @throws MissingActionNameException
     */
    public function shutdownObject(): void
    {
        if (!$this->publishingState->hasDocumentChangeSets()) {
            return;
        }

        $finalRequests = [];
        $moduleToPropertyMapping = [
            'focus_keyword_generator' => 'focusKeyword',
            'seo_title' => 'titleOverride',
            'meta_description' => 'metaDescription',
        ];

        $this->systemLogger->debug('Publishing Data (before sending):', $this->publishingState->toArray());

        // Iterate over all document nodes in publishingState
        foreach ($this->publishingState->getDocumentChangeSets() as $documentPath => $documentChangeSet) {
            $documentNode = $documentChangeSet->getDocumentNode();

            if ($documentNode === null) {
                $this->systemLogger->warning('Document node data missing for path: ' . $documentPath, [
                    'packageKey' => 'NEOSidekick.AiAssistant'
                ]);
                continue;
            }

            // Find the site for the current document
            $siteNodeName = null;
            $documentNodePath = explode('@', $documentNode['nodeContextPath'])[0];
            $pathParts = explode('/', $documentNodePath);
            if (isset($pathParts[1], $pathParts[2]) && $pathParts[1] === 'sites') {
                $siteNodeName = $pathParts[2];
            }

            if ($siteNodeName === null) {
                $this->systemLogger->warning('Could not determine site from document path: ' . $documentNode['path'], [
                    'packageKey' => 'NEOSidekick.AiAssistant'
                ]);
                continue; // Skip this document change set
            }

            $site = $this->siteRepository->findOneByNodeName($siteNodeName);
            if (!$site) {
                $this->systemLogger->warning('Could not find site with node name: ' . $siteNodeName, [
                    'packageKey' => 'NEOSidekick.AiAssistant'
                ]);
                continue; // Skip this document change set
            }

            // Get the active automation configuration for this site
            $automationConfig = $this->automationsConfigurationService->getActiveForSite($site);

            // Find the change DTO for the document node itself to access its properties
            $documentContentChange = $documentChangeSet->getContentChanges()[$documentNodePath] ?? null;

            $propertiesBefore = $documentContentChange?->before?->properties ?? $documentNode['properties'] ?? [];
            $propertiesAfter = $documentContentChange?->after?->properties ?? $documentNode['properties'] ?? [];

            // Initialize array of modules to call
            $modulesToCall = [];

            // Rule 1: Determine missing focus keywords
            if ($automationConfig->isDetermineMissingFocusKeywordsOnPublication() && empty($propertiesAfter['focusKeyword'])) {
                $modulesToCall[] = 'focus_keyword_generator';
            }

            // Rule 2: Re-determine existing focus keywords with loop prevention
            if (!empty($propertiesAfter['focusKeyword']) &&
                ($propertiesBefore['focusKeyword'] ?? null) === $propertiesAfter['focusKeyword'] &&
                !in_array('focus_keyword_generator', $modulesToCall, true) &&
                $automationConfig->isRedetermineExistingFocusKeywordsOnPublication()) {
                $modulesToCall[] = 'focus_keyword_generator';
            }

            // Rule 3: Generate empty SEO titles
            if ($automationConfig->isGenerateEmptySeoTitlesOnPublication() && empty($propertiesAfter['titleOverride'])) {
                $modulesToCall[] = 'seo_title';
            }

            // Rule 4: Regenerate existing SEO titles with loop prevention
            if (!empty($propertiesAfter['titleOverride']) &&
                ($propertiesBefore['titleOverride'] ?? null) === $propertiesAfter['titleOverride'] &&
                !in_array('seo_title', $modulesToCall, true) &&
                $automationConfig->isRegenerateExistingSeoTitlesOnPublication()) {
                $modulesToCall[] = 'seo_title';
            }

            // Rule 5: Generate empty meta descriptions
            if ($automationConfig->isGenerateEmptyMetaDescriptionsOnPublication() && empty($propertiesAfter['metaDescription'])) {
                $modulesToCall[] = 'meta_description';
            }

            // Rule 6: Regenerate existing meta descriptions with loop prevention
            if (!empty($propertiesAfter['metaDescription']) &&
                ($propertiesBefore['metaDescription'] ?? null) === $propertiesAfter['metaDescription'] &&
                !in_array('meta_description', $modulesToCall, true) &&
                $automationConfig->isRegenerateExistingMetaDescriptionsOnPublication()) {
                $modulesToCall[] = 'meta_description';
            }

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
                                'value' => $previewUrl . '&token=' . $readOnlyToken,
                            ],
                        ],
                        'request_id' => $documentNode['nodeContextPath'] . '#' . $propertyName,
                    ];
                }
            }
        }

        // If we have requests to send, construct and dispatch the final payload
        if (empty($finalRequests)) {
            $this->systemLogger->debug('No requests to send.');
            $this->publishingState = new PublishingState();
            return;
        }

        $writeToken = $this->jwtTokenFactory->getJsonWebToken();
        $finalPayload = [
            'requests' => $finalRequests,
            'webhook_url' => 'https://demoaiassistant.ddev.site/neosidekick/aiassistant/api/TBD',
            'webhook_authentication_header' => 'Bearer ' . $writeToken,
        ];

        $this->systemLogger->debug('Sending batch request:', $finalPayload);

//        if (!empty($this->endpoints)) {
//            $this->apiFacade->sendWebhookRequests('batchSeoAutomations', $finalPayload, $this->endpoints);
//        }

        $this->systemLogger->debug('Publishing Data (before cleanup):', $this->publishingState->toArray());

        // Reset the publishing state
        $this->publishingState = new PublishingState();
    }
}

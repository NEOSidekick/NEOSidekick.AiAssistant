<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Domain\Service\AutomationsConfigurationService;
use NEOSidekick\AiAssistant\Dto\ContentChangeDto;
use NEOSidekick\AiAssistant\Dto\PublishingState;
use NEOSidekick\AiAssistant\Dto\WorkspacePublishedDto;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
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
     * We'll send a single "WorkspacePublished" event for each workspace that had publishing.
     * This includes all nodes that were created, updated, or removed.
     */
    public function shutdownObject(): void
    {
        if (!$this->publishingState->hasDocumentChangeSets()) {
            return;
        }

        $this->systemLogger->debug('Publishing Data (before sending):', $this->publishingState->toArray());

        $eventName = 'workspacePublished';

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
            $pathParts = explode('/', $documentNode['nodeContextPath']);
            if (isset($pathParts[1]) && $pathParts[1] === 'sites' && isset($pathParts[2])) {
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
            $documentContentChange = $documentChangeSet->getContentChanges()[$documentNode['path']] ?? null;

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

            $changes = [];
            // Process content changes for this document
            foreach ($documentChangeSet->getContentChanges() as $contentChange) {
                $changeArray = $contentChange->toArray();
                if (!empty($changeArray)) {
                    $changes[] = $changeArray;
                }
            }

            // Create a WorkspacePublishedDto for this document node
            $workspacePublishedDto = new WorkspacePublishedDto(
                'WorkspacePublished',
                $this->publishingState->getWorkspaceName(),
                $changes,
                $modulesToCall
            );

            // Log the document node and its changes
            $this->systemLogger->debug('Document node with changes:', [
                'documentPath' => $documentPath,
                'documentNode' => $documentNode,
                'changes' => $changes,
                'dto' => $workspacePublishedDto->toArray()
            ]);

            // Send webhook for this document node only if there are modules to call
            if (!empty($modulesToCall) && !empty($this->endpoints)) {
                $this->apiFacade->sendWebhookRequests($eventName, $workspacePublishedDto->toArray(), $this->endpoints);
            }
        }

        $this->systemLogger->debug('Publishing Data (before cleanup):', $this->publishingState->toArray());

        // Reset the publishing state
        $this->publishingState = new PublishingState();
    }
}

<?php

namespace NEOSidekick\AiAssistant\Domain\Service;

use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;
use NEOSidekick\AiAssistant\Dto\DocumentChangeSet;

/**
 * Service for determining which automation modules to trigger based on document changes and configuration
 *
 * @Flow\Scope("singleton")
 */
class AutomationRulesService
{
    /**
     * Determines which automation modules should be triggered based on document changes and configuration
     *
     * This method analyzes the document change set and automation configuration to decide
     * which automation modules should be triggered. It implements six rules:
     * 1. Determine missing focus keywords
     * 2. Re-determine existing focus keywords
     * 3. Generate empty SEO titles
     * 4. Regenerate existing SEO titles
     * 5. Generate empty meta descriptions
     * 6. Regenerate existing meta descriptions
     *
     * @param DocumentChangeSet $documentChangeSet The document change set containing the changes to analyze
     * @param AutomationsConfiguration $automationsConfiguration The automation configuration with enabled/disabled features
     * @return array<string> Array of module identifiers to trigger
     */
    public function determineModulesToTrigger(DocumentChangeSet $documentChangeSet, AutomationsConfiguration $automationsConfiguration): array
    {
        $modulesToCall = [];

        // Extract the document node data from the change set
        $documentNode = $documentChangeSet->getDocumentNode();

        // Determine the document node's path, which is used as a key in the content changes array
        $documentNodePath = explode('@', $documentNode['nodeContextPath'])[0];

        // Retrieve the specific ContentChangeDto for the document node itself from the change set
        $documentContentChange = $documentChangeSet->getContentChanges()[$documentNodePath] ?? null;

        // Extract the node properties before the publish action
        $propertiesBefore = $documentContentChange?->before?->properties ?? $documentNode['properties'] ?? [];

        // Extract the node properties after the publish action
        $propertiesAfter = $documentContentChange?->after?->properties ?? $documentNode['properties'] ?? [];

        // Rule 1: Determine missing focus keywords
        if ($automationsConfiguration->isDetermineMissingFocusKeywordsOnPublication() && empty($propertiesAfter['focusKeyword'])) {
            $modulesToCall[] = 'focus_keyword_generator';
        }

        // Rule 2: Re-determine existing focus keywords with loop prevention
        if (!empty($propertiesAfter['focusKeyword']) &&
            ($propertiesBefore['focusKeyword'] ?? null) === $propertiesAfter['focusKeyword'] &&
            !in_array('focus_keyword_generator', $modulesToCall, true) &&
            $automationsConfiguration->isRedetermineExistingFocusKeywordsOnPublication()) {
            $modulesToCall[] = 'focus_keyword_generator';
        }

        // Rule 3: Generate empty SEO titles
        if ($automationsConfiguration->isGenerateEmptySeoTitlesOnPublication() && empty($propertiesAfter['titleOverride'])) {
            $modulesToCall[] = 'seo_title';
        }

        // Rule 4: Regenerate existing SEO titles with loop prevention
        if (!empty($propertiesAfter['titleOverride']) &&
            ($propertiesBefore['titleOverride'] ?? null) === $propertiesAfter['titleOverride'] &&
            !in_array('seo_title', $modulesToCall, true) &&
            $automationsConfiguration->isRegenerateExistingSeoTitlesOnPublication()) {
            $modulesToCall[] = 'seo_title';
        }

        // Rule 5: Generate empty meta descriptions
        if ($automationsConfiguration->isGenerateEmptyMetaDescriptionsOnPublication() && empty($propertiesAfter['metaDescription'])) {
            $modulesToCall[] = 'meta_description';
        }

        // Rule 6: Regenerate existing meta descriptions with loop prevention
        if (!empty($propertiesAfter['metaDescription']) &&
            ($propertiesBefore['metaDescription'] ?? null) === $propertiesAfter['metaDescription'] &&
            !in_array('meta_description', $modulesToCall, true) &&
            $automationsConfiguration->isRegenerateExistingMetaDescriptionsOnPublication()) {
            $modulesToCall[] = 'meta_description';
        }

        return $modulesToCall;
    }
}

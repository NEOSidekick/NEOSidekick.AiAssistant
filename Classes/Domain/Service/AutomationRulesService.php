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

        $rules = [
            [
                'module' => 'focus_keyword_generator',
                'property' => 'focusKeyword',
                'generate' => $automationsConfiguration->isDetermineMissingFocusKeywordsOnPublication(),
                'regenerate' => $automationsConfiguration->isRedetermineExistingFocusKeywordsOnPublication()
            ],
            [
                'module' => 'seo_title',
                'property' => 'titleOverride',
                'generate' => $automationsConfiguration->isGenerateEmptySeoTitlesOnPublication(),
                'regenerate' => $automationsConfiguration->isRegenerateExistingSeoTitlesOnPublication()
            ],
            [
                'module' => 'meta_description',
                'property' => 'metaDescription',
                'generate' => $automationsConfiguration->isGenerateEmptyMetaDescriptionsOnPublication(),
                'regenerate' => $automationsConfiguration->isRegenerateExistingMetaDescriptionsOnPublication()
            ]
        ];

        foreach ($rules as $rule) {
            // Check if this module is already slated for a call to prevent duplicate processing.
            if (in_array($rule['module'], $modulesToCall, true)) {
                continue;
            }

            // Ask the helper method if the conditions are met to trigger this module.
            $shouldTrigger = $this->shouldTriggerModule(
                $rule['property'],
                $rule['generate'],
                $rule['regenerate'],
                $propertiesBefore,
                $propertiesAfter
            );

            if ($shouldTrigger) {
                $modulesToCall[] = $rule['module'];
            }
        }

        return $modulesToCall;
    }

    /**
     * Determines if a module should be triggered for a specific property based on a set of rules.
     *
     * @param string $propertyName The name of the property to check (e.g., 'focusKeyword').
     * @param bool $generateForEmpty The configuration setting to generate if the property is empty.
     * @param bool $regenerateForExisting The configuration setting to regenerate if the property is unchanged.
     * @param array $propertiesBefore The node's properties before the change.
     * @param array $propertiesAfter The node's properties after the change.
     * @return bool True if the module should be triggered, false otherwise.
     */
    private function shouldTriggerModule(string $propertyName, bool $generateForEmpty, bool $regenerateForExisting, array $propertiesBefore, array $propertiesAfter): bool
    {
        // Rule: Trigger if the "generate for empty" option is on and the property is actually empty.
        if ($generateForEmpty && empty($propertiesAfter[$propertyName])) {
            return true;
        }

        // Rule: Trigger if the "regenerate for existing" option is on, the property is not empty,
        // and its value has not changed during this publication.
        if ($regenerateForExisting &&
            !empty($propertiesAfter[$propertyName]) &&
            ($propertiesBefore[$propertyName] ?? null) === $propertiesAfter[$propertyName]) {
            return true;
        }

        return false;
    }
}

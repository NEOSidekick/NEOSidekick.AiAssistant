<?php

namespace NEOSidekick\AiAssistant\Tests\Unit\Domain\Service;

use Neos\Flow\Tests\UnitTestCase;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;
use NEOSidekick\AiAssistant\Domain\Service\AutomationRulesService;
use NEOSidekick\AiAssistant\Dto\ContentChangeDto;
use NEOSidekick\AiAssistant\Dto\DocumentChangeSet;
use NEOSidekick\AiAssistant\Dto\NodeDataDto;

class AutomationRulesServiceTest extends UnitTestCase
{
    /**
     * @var AutomationRulesService
     */
    private $automationRulesService;

    /**
     * Set up the test case
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->automationRulesService = new AutomationRulesService();
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForMissingFocusKeyword(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['focusKeyword' => ''], // Empty focus keyword
            ['focusKeyword' => '']  // Still empty after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            true, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('focus_keyword_generator', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForExistingFocusKeyword(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['focusKeyword' => 'existing keyword'], // Existing focus keyword
            ['focusKeyword' => 'existing keyword']  // Same focus keyword after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            true,  // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('focus_keyword_generator', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForMissingSeoTitle(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['titleOverride' => ''], // Empty SEO title
            ['titleOverride' => '']  // Still empty after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            true,  // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('seo_title', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForExistingSeoTitle(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['titleOverride' => 'existing title'], // Existing SEO title
            ['titleOverride' => 'existing title']  // Same SEO title after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            true,  // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('seo_title', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForMissingMetaDescription(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['metaDescription' => ''], // Empty meta description
            ['metaDescription' => '']  // Still empty after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            true,  // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('meta_description', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testDetermineModulesToTriggerForExistingMetaDescription(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['metaDescription' => 'existing description'], // Existing meta description
            ['metaDescription' => 'existing description']  // Same meta description after publish
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            true   // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('meta_description', $result);
        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function testNoModulesTriggeredWhenAllFeaturesDisabled(): void
    {
        // Create mock objects
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['focusKeyword' => '', 'titleOverride' => '', 'metaDescription' => ''],
            ['focusKeyword' => '', 'titleOverride' => '', 'metaDescription' => '']
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            false, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            false, // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertEmpty($result);
    }

    /**
     * @test
     */
    public function testNoModulesTriggeredWhenNoConditionsMet(): void
    {
        // Create mock objects with all fields filled and changed
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['focusKeyword' => 'old keyword', 'titleOverride' => 'old title', 'metaDescription' => 'old description'],
            ['focusKeyword' => 'new keyword', 'titleOverride' => 'new title', 'metaDescription' => 'new description']
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            true, // determineMissingFocusKeywordsOnPublication
            true, // redetermineExistingFocusKeywordsOnPublication
            true, // generateEmptySeoTitlesOnPublication
            true, // regenerateExistingSeoTitlesOnPublication
            true, // generateEmptyMetaDescriptionsOnPublication
            true  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertEmpty($result);
    }

    /**
     * @test
     */
    public function testMultipleModulesTriggered(): void
    {
        // Create mock objects with multiple empty fields
        $documentChangeSet = $this->createDocumentChangeSetMock(
            ['focusKeyword' => '', 'titleOverride' => ''],
            ['focusKeyword' => '', 'titleOverride' => '']
        );

        $automationsConfiguration = $this->createAutomationsConfigurationMock(
            true, // determineMissingFocusKeywordsOnPublication
            false, // redetermineExistingFocusKeywordsOnPublication
            true,  // generateEmptySeoTitlesOnPublication
            false, // regenerateExistingSeoTitlesOnPublication
            false, // generateEmptyMetaDescriptionsOnPublication
            false  // regenerateExistingMetaDescriptionsOnPublication
        );

        // Execute the method under test
        $result = $this->automationRulesService->determineModulesToTrigger($documentChangeSet, $automationsConfiguration);

        // Assert the result
        self::assertContains('focus_keyword_generator', $result);
        self::assertContains('seo_title', $result);
        self::assertCount(2, $result);
    }

    /**
     * Helper method to create a DocumentChangeSet for testing
     *
     * @param array $propertiesBefore Properties before the publish action
     * @param array $propertiesAfter Properties after the publish action
     * @return DocumentChangeSet
     */
    private function createDocumentChangeSetMock(array $propertiesBefore, array $propertiesAfter): DocumentChangeSet
    {
        // Create the document node data
        $documentNodeData = [
            'nodeContextPath' => '/sites/example@live',
            'properties' => $propertiesAfter
        ];

        // Create the DocumentChangeSet instance
        $documentChangeSet = new DocumentChangeSet($documentNodeData);

        // Create NodeDataDto instances for before and after
        $beforeNodeDto = new NodeDataDto(
            'node-identifier',
            '/sites/example',
            'live',
            [],
            'example',
            $propertiesBefore
        );

        $afterNodeDto = new NodeDataDto(
            'node-identifier',
            '/sites/example',
            'live',
            [],
            'example',
            $propertiesAfter
        );

        // Create ContentChangeDto with the NodeDataDto instances
        $contentChangeDto = new ContentChangeDto($beforeNodeDto, $afterNodeDto);

        // Add the ContentChangeDto to the DocumentChangeSet
        $documentChangeSet->addContentChange('/sites/example', $contentChangeDto);

        return $documentChangeSet;
    }

    /**
     * Helper method to create a mock AutomationsConfiguration
     *
     * @param bool $determineMissingFocusKeywords
     * @param bool $redetermineExistingFocusKeywords
     * @param bool $generateEmptySeoTitles
     * @param bool $regenerateExistingSeoTitles
     * @param bool $generateEmptyMetaDescriptions
     * @param bool $regenerateExistingMetaDescriptions
     * @return AutomationsConfiguration
     */
    private function createAutomationsConfigurationMock(
        bool $determineMissingFocusKeywords,
        bool $redetermineExistingFocusKeywords,
        bool $generateEmptySeoTitles,
        bool $regenerateExistingSeoTitles,
        bool $generateEmptyMetaDescriptions,
        bool $regenerateExistingMetaDescriptions
    ): AutomationsConfiguration {
        $automationsConfiguration = $this->getMockBuilder(AutomationsConfiguration::class)
            ->disableOriginalConstructor()
            ->getMock();

        $automationsConfiguration->method('isDetermineMissingFocusKeywordsOnPublication')
            ->willReturn($determineMissingFocusKeywords);

        $automationsConfiguration->method('isRedetermineExistingFocusKeywordsOnPublication')
            ->willReturn($redetermineExistingFocusKeywords);

        $automationsConfiguration->method('isGenerateEmptySeoTitlesOnPublication')
            ->willReturn($generateEmptySeoTitles);

        $automationsConfiguration->method('isRegenerateExistingSeoTitlesOnPublication')
            ->willReturn($regenerateExistingSeoTitles);

        $automationsConfiguration->method('isGenerateEmptyMetaDescriptionsOnPublication')
            ->willReturn($generateEmptyMetaDescriptions);

        $automationsConfiguration->method('isRegenerateExistingMetaDescriptionsOnPublication')
            ->willReturn($regenerateExistingMetaDescriptions);

        return $automationsConfiguration;
    }
}

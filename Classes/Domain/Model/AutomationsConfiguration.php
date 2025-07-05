<?php
namespace NEOSidekick\AiAssistant\Domain\Model;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Domain\Enum\ApprovalProcessType;

/**
 * @Flow\Entity
 */
class AutomationsConfiguration
{
    /**
     * @Flow\Identity
     * @var string
     */
    protected string $identifier;

    /**
     * Determine missing focus keywords on publication
     *
     * @var bool
     */
    protected bool $determineMissingFocusKeywordsOnPublication = false;

    /**
     * Re-determine existing focus keywords on publication
     *
     * @var bool
     */
    protected bool $redetermineExistingFocusKeywordsOnPublication = false;

    /**
     * Automatically generate empty SEO titles on publication
     *
     * @var bool
     */
    protected bool $generateEmptySeoTitlesOnPublication = false;

    /**
     * Automatically generate empty meta descriptions on publication
     *
     * @var bool
     */
    protected bool $generateEmptyMetaDescriptionsOnPublication = false;

    /**
     * Automatically regenerate existing SEO titles on publication
     *
     * @var bool
     */
    protected bool $regenerateExistingSeoTitlesOnPublication = false;

    /**
     * Automatically regenerate existing meta descriptions on publication
     *
     * @var bool
     */
    protected bool $regenerateExistingMetaDescriptionsOnPublication = false;

    /**
     * Automatically generate empty alternative texts for images on publication
     *
     * @var bool
     */
    protected bool $generateEmptyImageAltTextsOnPublication = false;

    /**
     * Automatically generate empty title texts for images on publication
     *
     * @var bool
     */
    protected bool $generateEmptyImageTitleTextsOnPublication = false;

    /**
     * Include NEOSidekick briefing in the prompt
     *
     * @var bool
     */
    protected bool $includeNeosidekickBriefingInPrompt = false;

    /**
     * Approval process for SEO automations
     *
     * @var string
     */
    protected string $seoAutomationsApprovalProcess = ApprovalProcessType::APPROVE_AUTOMATICALLY;

    /**
     * Approval process for image alt text automations
     *
     * @var string
     */
    protected string $imageAltTextAutomationsApprovalProcess = ApprovalProcessType::APPROVE_AUTOMATICALLY;

    /**
     * Approval process for Brand Guard
     *
     * @var string
     */
    protected string $brandGuardApprovalProcess = ApprovalProcessType::APPROVE_AUTOMATICALLY;

    /**
     * Brand Guard prompt
     *
     * @var string
     * @ORM\Column(type="text")
     */
    protected string $brandGuardPrompt;

    public function __construct()
    {
        $this->identifier = sha1(getenv('FLOW_CONTEXT'));
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return bool
     */
    public function isDetermineMissingFocusKeywordsOnPublication(): bool
    {
        return $this->determineMissingFocusKeywordsOnPublication;
    }

    /**
     * @param bool $determineMissingFocusKeywordsOnPublication
     * @return void
     */
    public function setDetermineMissingFocusKeywordsOnPublication(bool $determineMissingFocusKeywordsOnPublication): void
    {
        $this->determineMissingFocusKeywordsOnPublication = $determineMissingFocusKeywordsOnPublication;
    }

    /**
     * @return bool
     */
    public function isRedetermineExistingFocusKeywordsOnPublication(): bool
    {
        return $this->redetermineExistingFocusKeywordsOnPublication;
    }

    /**
     * @param bool $redetermineExistingFocusKeywordsOnPublication
     * @return void
     */
    public function setRedetermineExistingFocusKeywordsOnPublication(bool $redetermineExistingFocusKeywordsOnPublication): void
    {
        $this->redetermineExistingFocusKeywordsOnPublication = $redetermineExistingFocusKeywordsOnPublication;
    }

    /**
     * @return bool
     */
    public function isGenerateEmptySeoTitlesOnPublication(): bool
    {
        return $this->generateEmptySeoTitlesOnPublication;
    }

    /**
     * @param bool $generateEmptySeoTitlesOnPublication
     * @return void
     */
    public function setGenerateEmptySeoTitlesOnPublication(bool $generateEmptySeoTitlesOnPublication): void
    {
        $this->generateEmptySeoTitlesOnPublication = $generateEmptySeoTitlesOnPublication;
    }

    /**
     * @return bool
     */
    public function isGenerateEmptyMetaDescriptionsOnPublication(): bool
    {
        return $this->generateEmptyMetaDescriptionsOnPublication;
    }

    /**
     * @param bool $generateEmptyMetaDescriptionsOnPublication
     * @return void
     */
    public function setGenerateEmptyMetaDescriptionsOnPublication(bool $generateEmptyMetaDescriptionsOnPublication): void
    {
        $this->generateEmptyMetaDescriptionsOnPublication = $generateEmptyMetaDescriptionsOnPublication;
    }

    /**
     * @return bool
     */
    public function isRegenerateExistingSeoTitlesOnPublication(): bool
    {
        return $this->regenerateExistingSeoTitlesOnPublication;
    }

    /**
     * @param bool $regenerateExistingSeoTitlesOnPublication
     * @return void
     */
    public function setRegenerateExistingSeoTitlesOnPublication(bool $regenerateExistingSeoTitlesOnPublication): void
    {
        $this->regenerateExistingSeoTitlesOnPublication = $regenerateExistingSeoTitlesOnPublication;
    }

    /**
     * @return bool
     */
    public function isRegenerateExistingMetaDescriptionsOnPublication(): bool
    {
        return $this->regenerateExistingMetaDescriptionsOnPublication;
    }

    /**
     * @param bool $regenerateExistingMetaDescriptionsOnPublication
     * @return void
     */
    public function setRegenerateExistingMetaDescriptionsOnPublication(bool $regenerateExistingMetaDescriptionsOnPublication): void
    {
        $this->regenerateExistingMetaDescriptionsOnPublication = $regenerateExistingMetaDescriptionsOnPublication;
    }

    /**
     * @return bool
     */
    public function isGenerateEmptyImageAltTextsOnPublication(): bool
    {
        return $this->generateEmptyImageAltTextsOnPublication;
    }

    /**
     * @param bool $generateEmptyImageAltTextsOnPublication
     * @return void
     */
    public function setGenerateEmptyImageAltTextsOnPublication(bool $generateEmptyImageAltTextsOnPublication): void
    {
        $this->generateEmptyImageAltTextsOnPublication = $generateEmptyImageAltTextsOnPublication;
    }

    /**
     * @return bool
     */
    public function isGenerateEmptyImageTitleTextsOnPublication(): bool
    {
        return $this->generateEmptyImageTitleTextsOnPublication;
    }

    /**
     * @param bool $generateEmptyImageTitleTextsOnPublication
     * @return void
     */
    public function setGenerateEmptyImageTitleTextsOnPublication(bool $generateEmptyImageTitleTextsOnPublication): void
    {
        $this->generateEmptyImageTitleTextsOnPublication = $generateEmptyImageTitleTextsOnPublication;
    }

    /**
     * @return bool
     */
    public function isIncludeNeosidekickBriefingInPrompt(): bool
    {
        return $this->includeNeosidekickBriefingInPrompt;
    }

    /**
     * @param bool $includeNeosidekickBriefingInPrompt
     * @return void
     */
    public function setIncludeNeosidekickBriefingInPrompt(bool $includeNeosidekickBriefingInPrompt): void
    {
        $this->includeNeosidekickBriefingInPrompt = $includeNeosidekickBriefingInPrompt;
    }

    /**
     * @return string
     */
    public function getSeoAutomationsApprovalProcess(): string
    {
        return $this->seoAutomationsApprovalProcess;
    }

    /**
     * @param string $seoAutomationsApprovalProcess
     * @return void
     * @throws \InvalidArgumentException if the value is not a valid approval process type
     */
    public function setSeoAutomationsApprovalProcess(string $seoAutomationsApprovalProcess): void
    {
        $validValues = [
            ApprovalProcessType::APPROVE_AUTOMATICALLY,
            ApprovalProcessType::REQUEST_VIA_EMAIL,
            ApprovalProcessType::REQUEST_VIA_SLACK
        ];

        if (!in_array($seoAutomationsApprovalProcess, $validValues, true)) {
            throw new \InvalidArgumentException('Invalid approval process type: ' . $seoAutomationsApprovalProcess);
        }

        $this->seoAutomationsApprovalProcess = $seoAutomationsApprovalProcess;
    }

    /**
     * @return string
     */
    public function getImageAltTextAutomationsApprovalProcess(): string
    {
        return $this->imageAltTextAutomationsApprovalProcess;
    }

    /**
     * @param string $imageAltTextAutomationsApprovalProcess
     * @return void
     * @throws \InvalidArgumentException if the value is not a valid approval process type
     */
    public function setImageAltTextAutomationsApprovalProcess(string $imageAltTextAutomationsApprovalProcess): void
    {
        $validValues = [
            ApprovalProcessType::APPROVE_AUTOMATICALLY,
            ApprovalProcessType::REQUEST_VIA_EMAIL,
            ApprovalProcessType::REQUEST_VIA_SLACK
        ];

        if (!in_array($imageAltTextAutomationsApprovalProcess, $validValues, true)) {
            throw new \InvalidArgumentException('Invalid approval process type: ' . $imageAltTextAutomationsApprovalProcess);
        }

        $this->imageAltTextAutomationsApprovalProcess = $imageAltTextAutomationsApprovalProcess;
    }

    /**
     * @return string
     */
    public function getBrandGuardApprovalProcess(): string
    {
        return $this->brandGuardApprovalProcess;
    }

    /**
     * @param string $brandGuardApprovalProcess
     * @return void
     * @throws \InvalidArgumentException if the value is not a valid approval process type
     */
    public function setBrandGuardApprovalProcess(string $brandGuardApprovalProcess): void
    {
        $validValues = [
            ApprovalProcessType::APPROVE_AUTOMATICALLY,
            ApprovalProcessType::REQUEST_VIA_EMAIL,
            ApprovalProcessType::REQUEST_VIA_SLACK
        ];

        if (!in_array($brandGuardApprovalProcess, $validValues, true)) {
            throw new \InvalidArgumentException('Invalid approval process type: ' . $brandGuardApprovalProcess);
        }

        $this->brandGuardApprovalProcess = $brandGuardApprovalProcess;
    }

    /**
     * @return string
     */
    public function getBrandGuardPrompt(): string
    {
        return $this->brandGuardPrompt;
    }

    /**
     * @param string $brandGuardPrompt
     * @return void
     */
    public function setBrandGuardPrompt(string $brandGuardPrompt): void
    {
        $this->brandGuardPrompt = $brandGuardPrompt;
    }
}

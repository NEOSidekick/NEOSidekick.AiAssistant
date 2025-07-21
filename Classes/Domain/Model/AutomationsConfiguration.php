<?php
namespace NEOSidekick\AiAssistant\Domain\Model;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;

/**
 * @Flow\Entity
 */
class AutomationsConfiguration
{
    /**
     * The site this configuration belongs to.
     *
     * @var Site
     * @ORM\OneToOne
     */
    protected $site;

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
     * @return Site|null
     */
    public function getSite(): ?Site
    {
        return $this->site;
    }

    /**
     * @param Site $site
     * @return void
     */
    public function setSite(Site $site): void
    {
        $this->site = $site;
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

}

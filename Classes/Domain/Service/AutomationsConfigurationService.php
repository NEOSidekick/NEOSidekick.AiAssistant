<?php

namespace NEOSidekick\AiAssistant\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Cache\Exception;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Persistence\Exception\UnknownObjectException;
use Neos\Neos\Domain\Model\Site;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;
use NEOSidekick\AiAssistant\Domain\Repository\AutomationsConfigurationRepository;

/**
 * @Flow\Scope("singleton")
 */
class AutomationsConfigurationService
{
    /**
     * @Flow\Inject
     * @var AutomationsConfigurationRepository
     */
    protected $repository;

    /**
     * @var VariableFrontend
     */
    protected $cache;

    public function getActiveForSite(Site $site): AutomationsConfiguration
    {
        $cacheIdentifier = $site->getNodeName();
        $automationsConfiguration = $this->cache->get($cacheIdentifier);
        if ($automationsConfiguration !== false) {
            return $automationsConfiguration;
        }

        $automationsConfiguration = $this->repository->findOneBySite($site);
        if ($automationsConfiguration !== null) {
            try {
                $this->cache->set($cacheIdentifier, $automationsConfiguration);
            } catch (Exception $e) {
                // Mute the exception to avoid breaking the flow
            }
            return $automationsConfiguration;
        }

        // Create a new default configuration if none exists
        $automationsConfiguration = new AutomationsConfiguration();
        $automationsConfiguration->setSite($site);
        return $automationsConfiguration;
    }

    public function createOrUpdate(AutomationsConfiguration $automationsConfiguration): void
    {
        try {
            $this->repository->update($automationsConfiguration);
        } catch (UnknownObjectException) {
            // If the object is not known, we assume it is a new one and persist it
            $this->repository->add($automationsConfiguration);
        }

        $cacheIdentifier = $automationsConfiguration->getSite()->getNodeName();
        $this->cache->remove($cacheIdentifier);
        try {
            $this->cache->set($cacheIdentifier, $automationsConfiguration);
        } catch (Exception $e) {
            // Mute the exception to avoid breaking the flow
        }
    }
}

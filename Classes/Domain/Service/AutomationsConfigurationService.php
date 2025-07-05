<?php

namespace NEOSidekick\AiAssistant\Domain\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Cache\Exception;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Persistence\Exception\UnknownObjectException;
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

    public function getActive(): AutomationsConfiguration
    {
        $identifier = sha1(getenv('FLOW_CONTEXT'));
        $automationsConfiguration = $this->cache->get($identifier);
        if ($automationsConfiguration !== false) {
            return $automationsConfiguration;
        }

        $automationsConfiguration = $this->repository->findActive();
        if ($automationsConfiguration !== null) {
            try {
                $this->cache->set($identifier, $automationsConfiguration);
            } catch (Exception $e) {
                // Mute the exception to avoid breaking the flow
            }
            return $automationsConfiguration;
        }

        // Create a new default configuration if none exists
        return new AutomationsConfiguration();
    }

    public function createOrUpdate(AutomationsConfiguration $automationsConfiguration): void
    {
        try {
            $this->repository->update($automationsConfiguration);
        } catch (UnknownObjectException) {
            // If the object is not known, we assume it is a new one and persist it
            $this->repository->add($automationsConfiguration);
        }
        $identifier = sha1(getenv('FLOW_CONTEXT'));
        $this->cache->remove($identifier);
        try {
            $this->cache->set($identifier, $automationsConfiguration);
        } catch (Exception $e) {
            // Mute the exception to avoid breaking the flow
        }
    }
}

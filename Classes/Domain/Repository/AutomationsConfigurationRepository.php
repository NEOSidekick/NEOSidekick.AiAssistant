<?php
namespace NEOSidekick\AiAssistant\Domain\Repository;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;

/**
 * @Flow\Scope("singleton")
 */
class AutomationsConfigurationRepository extends Repository
{
    public function findActive(): ?AutomationsConfiguration
    {
        $query = $this->createQuery();
        $query->matching($query->equals('identifier', sha1(getenv('FLOW_CONTEXT'))));
        $query->setLimit(1);
        return $query->execute()->getFirst();
    }
}

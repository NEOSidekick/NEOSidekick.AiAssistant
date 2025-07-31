<?php
namespace NEOSidekick\AiAssistant\Domain\Repository;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use Neos\Neos\Domain\Model\Site;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;

/**
 * @Flow\Scope("singleton")
 */
class AutomationsConfigurationRepository extends Repository
{
    public function findOneBySite(Site $site): ?AutomationsConfiguration
    {
        $query = $this->createQuery();
        $query->matching($query->equals('site', $site));
        $query->setLimit(1);
        return $query->execute()->getFirst();
    }
}

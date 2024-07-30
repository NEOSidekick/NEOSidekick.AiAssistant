<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Exception as NeosException;
use Neos\Neos\Routing\Exception\NoSiteException;

/**
 * @Flow\Scope("singleton")
 */
class SiteService
{
    public const SITES_ROOT_PATH = \Neos\Neos\Domain\Service\SiteService::SITES_ROOT_PATH;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @copyright Taken from: Neos\Neos\Routing\FrontendNodeRoutePartHandler::getSiteByHostname()
     *
     * Returns a site matching the given $hostName
     *
     * @param string $hostName
     *
     * @return Site
     * @throws NoSiteException
     */
    public function getSiteByHostName(string $hostName): Site
    {
        $domain = $this->domainRepository->findOneByHost($hostName, true);
        if ($domain !== null) {
            return $domain->getSite();
        }
        try {
            $defaultSite = $this->siteRepository->findDefault();
            if ($defaultSite === null) {
                throw new NoSiteException('Failed to determine current site because no default site is configured', 1604929674);
            }
        } catch (NeosException $exception) {
            throw new NoSiteException(sprintf('Failed to determine current site because no domain is specified matching host of "%s" and no default site could be found: %s', $hostName, $exception->getMessage()), 1604860219, $exception);
        }
        return $defaultSite;
    }
}

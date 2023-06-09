<?php

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Service\UserService;

class ServiceController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $settings = [];

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @return void
     */
    public function configurationAction(): void
    {
        $currentDomain = $this->domainRepository->findOneByActiveRequest();

        if ($currentDomain !== null) {
            $currentSite = $currentDomain->getSite();
        } else {
            $currentSite = $this->siteRepository->findFirstOnline();
        }

        $configuration = [
            ...$this->settings,
            'userId' => sha1($this->persistenceManager->getIdentifierByObject($this->userService->getBackendUser())),
            'siteName' => $currentSite ? $currentSite->getName() : '',
            'domain' => $currentDomain ? $currentDomain->getHostname() : $_SERVER['SERVER_NAME']
        ];
        $this->view->assign('value', $configuration);
    }
}

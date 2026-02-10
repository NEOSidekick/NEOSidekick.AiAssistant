<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Error\Messages\Message;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Domain\Model\Domain;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use NEOSidekick\AiAssistant\Domain\Model\AutomationsConfiguration;
use NEOSidekick\AiAssistant\Domain\Service\AutomationsConfigurationService;

/**
 * Controller for the "Automations" backend module
 *
 * @noinspection PhpUnused
 */
class AutomationsController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * This is needed for type hinting in the IDE
     *
     * @var FusionView
     */
    protected $view;

    /**
     * @Flow\Inject(lazy=false)
     * @var AutomationsConfigurationService
     */
    protected AutomationsConfigurationService $automationsConfigurationService;

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
     * @param FusionView $view
     *
     * @return void
     */
    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule');
    }

    /**
     * Display the automations configuration form
     *
     * @return void
     */
    public function indexAction(): void
    {
        $currentSite = $this->getCurrentSite();

        if ($currentSite !== null) {
            $automationsConfiguration = $this->automationsConfigurationService->getActiveForSite($currentSite);
            $this->view->assign('automationsConfiguration', $automationsConfiguration);
            $this->view->assign('currentSiteName', $currentSite->getName());
        } else {
            $this->view->assign('automationsConfiguration', null);
            $this->view->assign('currentSiteName', 'No site detected');
        }
    }

    /**
     * Update the automations configuration
     *
     * @param AutomationsConfiguration $automationsConfiguration
     * @return void
     */
    public function updateAction(AutomationsConfiguration $automationsConfiguration): void
    {
        $currentSite = $this->getCurrentSite();

        if ($currentSite === null) {
            $this->addFlashMessage('No site could be detected. Configuration not saved.', '', Message::SEVERITY_ERROR);
            $this->redirect('index');
            return;
        }

        $automationsConfiguration->setSite($currentSite);
        $this->automationsConfigurationService->createOrUpdate($automationsConfiguration);

        $this->addFlashMessage('The automations configuration has been updated.');
        $this->redirect('index');
    }

    /**
     * @return Site|null
     */
    protected function getCurrentSite(): ?Site
    {
        $httpRequest = $this->controllerContext->getRequest()->getHttpRequest();
        $hostname = $httpRequest->getUri()->getHost();

        // Try to find site by hostname first (existing logic)
        $matchingDomains = $this->domainRepository->findByHost($hostname, true);
        if (isset($matchingDomains[0])) {
            /** @var Domain $domain */
            $domain = $matchingDomains[0];
            return $domain->getSite();
        }

        // If no domain matches, check if there's only one site
        $allSites = $this->siteRepository->findAll();
        if ($allSites->count() === 1) {
            return $allSites->getFirst();
        }

        return null;
    }
}

<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
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
        $automationsConfiguration = $this->automationsConfigurationService->getActive();
        $this->view->assign('automationsConfiguration', $automationsConfiguration);
    }

    /**
     * Update the automations configuration
     *
     * @param AutomationsConfiguration $automationsConfiguration
     * @return void
     */
    public function updateAction(AutomationsConfiguration $automationsConfiguration): void
    {
        $this->automationsConfigurationService->createOrUpdate($automationsConfiguration);

        $this->addFlashMessage('The automations configuration has been updated.');
        $this->redirect('index');
    }
}

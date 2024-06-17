<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * @noinspection PhpUnused
 * @Flow\Scope("singleton")
 */
class OverviewController extends AbstractModuleController
{
    /**
     * @Flow\InjectConfiguration(package="Neos.Neos")
     * @var array
     */
    protected $neosSettings;

    public function indexAction(): void
    {
        $this->view->assign('neosSettings', $this->neosSettings);
    }
}

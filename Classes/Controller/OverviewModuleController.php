<?php

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Neos\Controller\Module\AbstractModuleController;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class OverviewModuleController extends AbstractModuleController
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

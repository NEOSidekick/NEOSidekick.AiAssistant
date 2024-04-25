<?php

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Module\AbstractModuleController;

/**
 * @noinspection PhpUnused
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

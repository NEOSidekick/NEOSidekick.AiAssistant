<?php

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\JsonView;

class ServiceController extends ActionController
{
    protected $defaultViewObjectName = JsonView::class;

    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $settings = [];

    /**
     * @return void
     */
    public function configurationAction(): void
    {
        $this->view->assign('value', $this->settings);
    }
}

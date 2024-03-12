<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Backend\ModuleController;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Service\UserService;

class AssetModuleController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    protected function initializeView(ViewInterface $view)
    {
        parent::initializeView($view);
        $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/BackendModule');
    }

    /**
     * @return void
     */
    public function indexAction()
    {
        $user = $this->userService->getBackendUser();
        $this->view->assign('user', $user);
        $this->view->assign('interfaceLanguage', $this->userService->getInterfaceLanguage());
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
    }
}

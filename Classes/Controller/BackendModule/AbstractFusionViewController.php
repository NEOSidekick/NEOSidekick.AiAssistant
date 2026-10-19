<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Service\UserService;
use Throwable;

abstract class AbstractFusionViewController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * This is needed for type hinting in the IDE
     *
     * @var FusionView
     */
    protected $view;

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
     * @return void
     */
    protected function abstractIndexAction(): void
    {
        $user = $this->userService->getBackendUser();
        $this->view->assign('user', $user);
        $this->view->assign('interfaceLanguage', $this->userService->getInterfaceLanguage());
        $this->view->assign('csrfToken', $this->securityContext->getCsrfProtectionToken());
    }

    /**
     * A link to another Neos backend module. Built on the MAIN request: this controller's own
     * uriBuilder is bound to the module sub-request, which would namespace the arguments as this
     * module's own ({@see MenuHelper}).
     */
    protected function neosBackendModuleUri(string $modulePath): string
    {
        try {
            $uriBuilder = clone $this->uriBuilder;
            $uriBuilder->setRequest($this->request->getMainRequest());

            return $uriBuilder->reset()->uriFor('index', ['module' => $modulePath], 'Backend\\Module', 'Neos.Neos');
        } catch (Throwable $throwable) {
            return '';
        }
    }
}

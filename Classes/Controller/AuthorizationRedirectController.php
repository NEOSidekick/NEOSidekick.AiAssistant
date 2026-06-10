<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;

class AuthorizationRedirectController extends ActionController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * @var FusionView
     */
    protected $view;

    protected function initializeView(ViewInterface $view): void
    {
        parent::initializeView($view);
        if ($view instanceof FusionView) {
            $view->setFusionPathPattern('resource://NEOSidekick.AiAssistant/Private/Agent');
        }
    }

    public function indexAction(string $state = ''): void
    {
        $authorizationUrl = '/neosidekick/agent/request-authorization';
        if ($state !== '') {
            $authorizationUrl .= '?' . http_build_query(['state' => $state], '', '&', PHP_QUERY_RFC3986);
        }

        $this->view->assign('authorizationUrl', $authorizationUrl);
    }
}

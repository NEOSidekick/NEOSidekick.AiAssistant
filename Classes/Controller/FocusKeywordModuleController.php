<?php

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Flow\Security\Context;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Neos\Service\UserService;

/**
 * @noinspection PhpUnused
 */
class FocusKeywordModuleController extends DocumentNodeModuleController
{
    /**
     * @return void
     */
    public function indexAction(): void
    {
        // not sure why this is not correctly inherited from the parent class
        $this->abstractIndexAction();
    }
}

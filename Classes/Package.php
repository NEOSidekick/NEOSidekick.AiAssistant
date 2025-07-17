<?php

namespace NEOSidekick\AiAssistant;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use NEOSidekick\AiAssistant\Service\NodePublishingListener;

class Package extends \Neos\Flow\Package
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Workspace::class, 'beforeNodePublishing', NodePublishingListener::class, 'handleBeforeNodePublishing');
        $dispatcher->connect(Workspace::class, 'afterNodePublishing', NodePublishingListener::class, 'handleAfterNodePublishing');
    }
}

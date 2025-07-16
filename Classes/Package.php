<?php

namespace NEOSidekick\AiAssistant;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\PublishingService;
use Neos\Flow\Core\Bootstrap;
use NEOSidekick\AiAssistant\Service\SignalCollectionService;

class Package extends \Neos\Flow\Package
{
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Node::class, 'nodeAdded', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(Node::class, 'nodeUpdated', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(Node::class, 'nodePropertyChanged', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(Node::class, 'nodeRemoved', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(PublishingService::class, 'nodePublished', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(PublishingService::class, 'nodeDiscarded', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(Workspace::class, 'beforeNodePublishing', SignalCollectionService::class, 'registerSignal');
        $dispatcher->connect(Workspace::class, 'afterNodePublishing', SignalCollectionService::class, 'registerSignal');
    }
}

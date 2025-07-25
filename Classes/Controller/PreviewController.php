<?php
namespace NEOSidekick\AiAssistant\Controller;

/*
 * This file is part of the NEOSidekick.AiAssistant package.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Frontend\NodeController;
use Neos\ContentRepository\Domain\Model\NodeInterface;

/**
 * Controller for previewing nodes in the NEOSidekick AI Assistant.
 *
 * This additional controller is necessary because here we can authenticate
 * with our JtwAuthenticationProvider, without having to tamper with
 * the Neos.Neos (preview) NodeController.
 *
 * IMPORTANT: Keep the use statement for Annotations, otherwise the class extension won't work.
 */
class PreviewController extends NodeController
{
    /**
     * Previews the specified node by rendering it with the Fusion preview view
     *
     * @param NodeInterface|null $node
     * @return string
     * @throws \Neos\Neos\Controller\Exception\NodeNotFoundException
     * @throws \Neos\Neos\Controller\Exception\UnresolvableShortcutException
     */
    public function previewAction(NodeInterface $node = null)
    {
        return parent::previewAction($node);
    }
}

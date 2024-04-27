<?php

namespace NEOSidekick\AiAssistant\Controller;

/**
 * @noinspection PhpUnused
 */
class SeoTitleAndMetaDescriptionModuleController extends DocumentNodeModuleController
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

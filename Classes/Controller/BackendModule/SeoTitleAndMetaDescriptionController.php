<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

/**
 * @noinspection PhpUnused
 */
class SeoTitleAndMetaDescriptionController extends AbstractFusionViewController
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

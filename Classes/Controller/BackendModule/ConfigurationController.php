<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;

/**
 * @noinspection PhpUnused
 */
class ConfigurationController extends AbstractModuleController
{
    protected $defaultViewObjectName = FusionView::class;

    /**
     * This is needed for type hinting in the IDE
     *
     * @var FusionView
     */
    protected $view;

    /**
     * @Flow\InjectConfiguration(path="apikey")
     * @var string
     */
    protected string $apiKey;

    /**
     * @Flow\InjectConfiguration(path="Internal.apiDomain")
     * @var string
     */
    protected string $apiDomain;

    /**
     * @Flow\Inject
     * @var NEOSidekickInternalHelper
     */
    protected $neosidekickInternalHelper;

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

    public function indexAction(): void
    {
        $this->view->assign('apiKey', $this->apiKey);
        $this->view->assign('apiDomain', $this->apiDomain);
        $this->view->assign('siteDomain', $this->getSiteDomain());
    }

    /**
     * Derives the current site's base URL as `scheme://host`, URL-encoded, so it can be
     * passed to the settings iframe the same way the chat iframes receive their `domain`
     * parameter. Reuses NEOSidekickInternalHelper::domain(), which resolves the active
     * request's domain first and falls back to the current HTTP request's scheme and host.
     * Returns an empty string when no host can be determined.
     *
     * @return string
     */
    protected function getSiteDomain(): string
    {
        $domain = $this->neosidekickInternalHelper->domain();
        if (empty(parse_url($domain, PHP_URL_HOST))) {
            return '';
        }
        return rawurlencode($domain);
    }
}

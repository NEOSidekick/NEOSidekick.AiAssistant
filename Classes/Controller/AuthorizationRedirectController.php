<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\View\ViewInterface;
use Neos\Fusion\View\FusionView;

class AuthorizationRedirectController extends ActionController
{
    /**
     * Bounds every consent query value this page carries over. The external consent screen is
     * reachable with a hand-crafted query, so nothing unbounded may be rebuilt into a URL.
     */
    protected const MAX_FORWARDED_ARGUMENT_LENGTH = 200;

    /**
     * The query keys the external consent hand-off carries; anything else Laravel sends would be
     * dropped here anyway, so the list is the contract.
     */
    protected const FORWARDED_ARGUMENT_NAMES = ['state', 'consumer', 'client_name', 'redirect_host'];

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

    /**
     * The bounce page for installs whose SameSite session cookie is not sent on the cross-site
     * entry: it re-enters the consent page from a same-site navigation. Every value the external
     * consent screen needs must survive that hop - Flow drops undeclared arguments, so they are
     * rebuilt into the target URL explicitly.
     */
    public function indexAction(string $state = ''): void
    {
        $authorizationUrl = '/neosidekick/agent/request-authorization';
        $forwardedArguments = $this->collectForwardedArguments($state);
        if ($forwardedArguments !== []) {
            $authorizationUrl .= '?' . http_build_query($forwardedArguments, '', '&', PHP_QUERY_RFC3986);
        }

        $this->view->assign('authorizationUrl', $authorizationUrl);
    }

    /**
     * @return array<string, string>
     */
    protected function collectForwardedArguments(string $state): array
    {
        $forwardedArguments = [];
        foreach (self::FORWARDED_ARGUMENT_NAMES as $argumentName) {
            $value = $argumentName === 'state' ? $state : $this->readRequestArgument($argumentName);
            if ($value === '') {
                continue;
            }
            $forwardedArguments[$argumentName] = mb_substr($value, 0, self::MAX_FORWARDED_ARGUMENT_LENGTH);
        }

        return $forwardedArguments;
    }

    /**
     * Read straight off the request rather than through action arguments: the consent query keys
     * are snake_case and are pure pass-through, so they never become mapped controller arguments.
     */
    protected function readRequestArgument(string $argumentName): string
    {
        if (!$this->request->hasArgument($argumentName)) {
            return '';
        }

        $value = $this->request->getArgument($argumentName);

        return is_string($value) ? $value : '';
    }
}

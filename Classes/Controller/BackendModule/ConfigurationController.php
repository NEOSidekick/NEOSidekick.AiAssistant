<?php

namespace NEOSidekick\AiAssistant\Controller\BackendModule;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\Utility\LogEnvironment;
use NEOSidekick\AiAssistant\Dto\AgentSigningKeyPushResult;
use NEOSidekick\AiAssistant\EelHelper\NEOSidekickInternalHelper;
use NEOSidekick\AiAssistant\Exception\AgentTokenException;
use NEOSidekick\AiAssistant\Service\AgentSigningKeyPushService;
use NEOSidekick\AiAssistant\Service\AgentTokenService;
use Psr\Log\LoggerInterface;

/**
 * @noinspection PhpUnused
 */
class ConfigurationController extends AbstractFusionViewController
{
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
     * @Flow\Inject
     * @var AgentSigningKeyPushService
     */
    protected $agentSigningKeyPushService;

    /**
     * @Flow\Inject
     * @var AgentTokenService
     */
    protected $agentTokenService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    public function indexAction(): void
    {
        $isAdministrator = $this->securityContext->hasRole('Neos.Neos:Administrator');
        $this->view->assign('apiKey', $this->apiKey);
        $this->view->assign('apiDomain', $this->apiDomain);
        $this->view->assign('siteDomain', $this->getSiteDomain());
        $this->view->assign('embedToken', $this->getEmbedToken());
        $this->view->assign('isAdministrator', $isAdministrator);
        $this->view->assign('connectionHealth', $this->getConnectionHealth($isAdministrator ? $this->agentSigningKeyPushService->pushExistingKey() : null));
    }

    /**
     * Whether the connection to NEOSidekick is in order; the page says a line only when it is
     * not, and every remedy lives in the key management module. The verdict comes from the push
     * made on this render; only when that push was skipped (throttled or keyless) does the
     * record decide.
     *
     * @param AgentSigningKeyPushResult|null $forcedPushResult The answer of the push made on this render, null when it was skipped
     */
    protected function getConnectionHealth(?AgentSigningKeyPushResult $forcedPushResult = null): bool
    {
        if ($forcedPushResult !== null) {
            return $forcedPushResult->successful && $forcedPushResult->hostsResult === 'accepted';
        }

        $recordedStatus = $this->agentSigningKeyPushService->getPushStatus()['status'] ?? 'none';

        return $recordedStatus === 'confirmed' || $recordedStatus === 'reenrolled';
    }

    /**
     * Mints the short-lived embed token the settings iframe carries, so the embedded page knows
     * which editor - not merely which account - is looking at it.
     *
     * A failure (no signing keypair, legacy install) is not fatal: the token is omitted and the
     * module still renders, with the editor-scoped section hidden.
     *
     * @return string|null
     */
    protected function getEmbedToken(): ?string
    {
        try {
            return $this->agentTokenService->generateEmbedToken();
        } catch (AgentTokenException $exception) {
            $this->logger->warning(
                'NEOSidekick settings module could not mint an embed token: ' . $exception->getMessage(),
                LogEnvironment::fromMethodName(__METHOD__)
            );

            return null;
        }
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

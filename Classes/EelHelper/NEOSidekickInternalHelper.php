<?php

namespace NEOSidekick\AiAssistant\EelHelper;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\BaseUriProvider;
use Neos\Flow\Http\Exception as HttpException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Flow\Security\Cryptography\HashService;
use Neos\Flow\Session\SessionManagerInterface;
use Neos\Neos\Domain\Repository\DomainRepository;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Service\UserService;

class NEOSidekickInternalHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\InjectConfiguration()
     * @var array
     */
    protected $settings = [];

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    /**
     * @Flow\Inject
     * @var UserService
     */
    protected $userService;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var DomainRepository
     */
    protected $domainRepository;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var PrivilegeManagerInterface
     */
    protected $privilegeManager;

    /**
     * @Flow\Inject
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @Flow\Inject
     * @var SessionManagerInterface
     */
    protected $sessionManager;

    /**
     * @Flow\Inject
     * @var SecurityContext
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var BaseUriProvider
     */
    protected $baseUriProvider;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensions;

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow", path="session.cookie.samesite")
     * @var string|null
     */
    protected $sessionCookieSameSite;

    public function isEnabled(): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted('NEOSidekick.AiAssistant:CanUse');
    }

    public function userId(): string
    {
        return sha1($this->persistenceManager->getIdentifierByObject($this->userService->getBackendUser()));
    }

    public function sessionId(): string
    {
        $session = $this->sessionManager->getCurrentSession();
        return $session->isStarted() ? $session->getId() : '';
    }

    public function sessionsIsSameSite(): bool
    {
        return strtolower($this->sessionCookieSameSite ?? '') === 'strict';
    }

    public function apiDomain(): string
    {
        return $this->settings['Internal']['apiDomain'];
    }

    /**
     * The backend session's CSRF protection token, exposed to the Neos UI plugin so it can
     * make CSRF-protected same-origin POSTs (e.g. silent re-authorization to do-authorize).
     */
    public function csrfToken(): string
    {
        return $this->securityContext->getCsrfProtectionToken();
    }

    public function apiKey(): string
    {
        return $this->settings['apikey'];
    }

    public function domain(): string
    {
        $currentDomain = $this->domainRepository->findOneByActiveRequest();
        if ($currentDomain) {
            $scheme = $currentDomain->getScheme() ?: $this->schemeFromActiveRequest();
            return "$scheme://" . $currentDomain->getHostname();
        }

        // No matching Neos domain record: derive the public base URI from the
        // active HTTP request rather than the raw superglobals. ServerRequest::
        // getUriFromGlobals() bypasses Flow's trusted-proxy handling and would
        // leak the internal upstream host (e.g. "web") whenever the site runs
        // behind a reverse proxy — for example a headless / Zebra Next.js
        // frontend. BaseUriProvider honours the configured baseUri and the
        // trusted-proxy corrected request, so it returns the real public host.
        try {
            return rtrim((string)$this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest(), '/');
        } catch (HttpException $exception) {
            // No active HTTP request (e.g. CLI) and no configured baseUri:
            // fall back to the previous globals-based behaviour as a last resort.
            $uriFromGlobals = ServerRequest::getUriFromGlobals();
            $schemeFromGlobals = $uriFromGlobals->getScheme() ?: 'http';
            return "$schemeFromGlobals://" . $uriFromGlobals->getHost();
        }
    }

    private function schemeFromActiveRequest(): string
    {
        try {
            return $this->baseUriProvider->getConfiguredBaseUriOrFallbackToCurrentRequest()->getScheme() ?: 'https';
        } catch (HttpException $exception) {
            return ServerRequest::getUriFromGlobals()->getScheme() ?: 'http';
        }
    }

    public function siteName(): string
    {
        $currentDomain = $this->domainRepository->findOneByActiveRequest();
        if ($currentDomain !== null) {
            $currentSite = $currentDomain->getSite();
        } else {
            $currentSite = $this->siteRepository->findFirstOnline();
        }
        return $currentSite ? $currentSite->getName() : '';
    }

    public function referrer(): ?string
    {
        return $this->settings['referrer'] ?? null;
    }

    public function defaultLanguage(): ?string
    {
        return $this->settings['defaultLanguage'] ?? null;
    }

    public function chatSidebarEnabled(): bool
    {
        return $this->settings['chatSidebarEnabled'] ?? false;
    }

    public function modifyTextModalPreferCustomPrompt(): bool
    {
        return $this->settings['modifyTextModal']['preferCustomPrompt'] ?? false;
    }

    public function altTextGeneratorModuleConfiguration(): ?array
    {
        return $this->settings['altTextGeneratorModule'] ?? null;
    }

    public function recommendNeosAssetCachePackage(): bool
    {
        return !$this->packageManager->isPackageAvailable('Webandco.AssetUsageCache');
    }

    public function languageDimensionValues(): array
    {
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return [];
        }

        return array_keys($this->contentDimensions[$this->languageDimensionName]['presets']);
    }

    /**
     * Sitegeist.LostInTranslation allows automatic transcription into different languages
     * with `translationStrategy: 'sync'`. We do not want to write automatically synced properties,
     * but we want to show an explanation in the UI.
     *
     * @return array
     */
    public function languageDimensionSyncPresets(): array
    {
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return [];
        }
        $presets = $this->contentDimensions[$this->languageDimensionName]['presets'] ?? [];
        $result = [];
        foreach ($presets as $presetIdentifier => $presetConfiguration) {
            $options = $presetConfiguration['options'] ?? [];
            if (($options['translationStrategy'] ?? null) === 'sync') {
                $result[] = $presetIdentifier;
            }
        }
        return $result;
    }

    /**
     * Sitegeist.LostInTranslation allows automatic transcription into different languages
     * with `translationStrategy: 'sync'`. We do not want to write automatically synced properties.
     *
     * @return array
     */
    public function languageDimensionValuesEnabledForEditing(): array
    {
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return [];
        }
        $presets = $this->contentDimensions[$this->languageDimensionName]['presets'] ?? [];
        $result = [];
        foreach ($presets as $presetIdentifier => $presetConfiguration) {
            $options = $presetConfiguration['options'] ?? [];
            if (($options['translationStrategy'] ?? null) !== 'sync') {
                $result[] = $presetIdentifier;
            }
        }
        return $result;
    }

    /**
     * @inheritDoc
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}

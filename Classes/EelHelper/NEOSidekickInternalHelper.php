<?php

namespace NEOSidekick\AiAssistant\EelHelper;

use GuzzleHttp\Psr7\ServerRequest;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Flow\Security\Authorization\PrivilegeManagerInterface;
use Neos\Flow\Security\Cryptography\HashService;
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
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensions;

    public function isEnabled(): bool
    {
        return $this->privilegeManager->isPrivilegeTargetGranted('NEOSidekick.AiAssistant:CanUse');
    }

    public function userId(): string
    {
        return sha1($this->persistenceManager->getIdentifierByObject($this->userService->getBackendUser()));
    }

    public function apiDomain(): string
    {
        if (isset($this->settings['developmentBuild']) && $this->settings['developmentBuild'] === true) {
            return 'https://api-staging.neosidekick.com';
        }

        return 'https://api.neosidekick.com';
    }

    public function apiKey(): string
    {
        return $this->settings['apikey'];
    }

    public function domain(): string
    {
        $currentDomain = $this->domainRepository->findOneByActiveRequest();
        if ($currentDomain) {
            $scheme = $currentDomain->getScheme() ?: 'http';
            return "$scheme://" . $currentDomain->getHostname();
        }

        $uriFromGlobals = ServerRequest::getUriFromGlobals();
        $schemeFromGlobals = $uriFromGlobals->getScheme() ?: 'http';
        return "$schemeFromGlobals://" . $uriFromGlobals->getHost();
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

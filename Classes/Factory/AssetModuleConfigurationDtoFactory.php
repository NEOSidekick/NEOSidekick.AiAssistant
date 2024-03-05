<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\Flow\Annotations as Flow;
use NEOSidekick\AiAssistant\Dto\AssetModuleConfigurationDto;
use NEOSidekick\AiAssistant\Exception\AssetModuleConfigurationException;

/**
 * @Flow\Scope("singleton")
 */
class AssetModuleConfigurationDtoFactory
{
    public const ALLOWED_PROPERTY_NAMES = ['title', 'caption', 'copyrightNotice'];

    /**
     * @Flow\InjectConfiguration(path="altTagGenerator")
     * @var array{queryOnlyAssetsInUse: ?bool, propertyName: ?string, pageSize: ?int}
     */
    protected array $settings;

    /**
     * @throws AssetModuleConfigurationException
     */
    public function createFromSettings(): ?AssetModuleConfigurationDto
    {
        if (!isset($this->settings['onlyInUse']) || !isset($this->settings['propertyName'])) {
            return null;
        }

        if (!in_array($this->settings['propertyName'], self::ALLOWED_PROPERTY_NAMES)) {
            throw new AssetModuleConfigurationException('The configured propertyName is not allowed. Use one of title, caption or copyrightNotice', 1706525979551);
        }

        return new AssetModuleConfigurationDto(
            $this->settings['queryOnlyAssetsInUse'],
            $this->settings['propertyName'],
            $this->settings['pageSize'] ?: 10
        );
    }
}

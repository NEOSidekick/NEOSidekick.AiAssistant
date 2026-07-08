<?php

namespace NEOSidekick\AiAssistant\Service;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Central access to "the" content repository this package operates on.
 *
 * Neos 9 supports multiple content repositories; this package currently assumes a single one.
 * The id is configurable (NEOSidekick.AiAssistant.contentRepositoryId, default "default") and
 * every service resolves it through this provider, so becoming properly multi-CR-aware later
 * (e.g. deriving the id from the request's SiteDetectionResult) is a change in one place.
 *
 * @Flow\Scope("singleton")
 */
class ContentRepositoryProvider
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @Flow\InjectConfiguration(path="contentRepositoryId")
     * @var string
     */
    protected $contentRepositoryId;

    public function getContentRepositoryId(): ContentRepositoryId
    {
        return ContentRepositoryId::fromString($this->contentRepositoryId ?: 'default');
    }

    public function getContentRepository(): ContentRepository
    {
        return $this->contentRepositoryRegistry->get($this->getContentRepositoryId());
    }
}

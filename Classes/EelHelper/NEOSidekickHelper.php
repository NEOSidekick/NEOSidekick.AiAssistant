<?php

namespace NEOSidekick\AiAssistant\EelHelper;

use Exception;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Eel\ProtectedContextAwareInterface;

class NEOSidekickHelper implements ProtectedContextAwareInterface
{
    #[Flow\Inject]
    protected \Neos\ContentRepositoryRegistry\ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @throws Exception
     */
    public function getImageAltText(Node $node, string $propertyName): ?string
    {
        return $this->getImageText($node, $propertyName, 'NEOSidekick.AiAssistant/Inspector/Editors/ImageAltTextEditor');
    }

    /**
     * @throws Exception
     */
    public function getImageTitle(Node $node, string $propertyName): ?string
    {
        return $this->getImageText($node, $propertyName, 'NEOSidekick.AiAssistant/Inspector/Editors/ImageTitleEditor');
    }

    /**
     * @throws Exception
     */
    protected function getImageText(Node $node, string $propertyName, string $expectedEditor): ?string
    {
        if ($node->hasProperty($propertyName)) {
            return $node->getProperty($propertyName);
        }
        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);

        $nodeType = $contentRepository->getNodeTypeManager()->getNodeType($node->nodeTypeName);
        $propertyConfiguration = $nodeType?->getFullConfiguration()['properties'][$propertyName] ?? null;
        if ($propertyConfiguration === null) {
            return null;
        }
        $editor = $propertyConfiguration['ui']['inspector']['editor'] ?? null;
        $editorOptions = $propertyConfiguration['ui']['inspector']['editorOptions'] ?? [];
        $imagePropertyName = $editorOptions['imagePropertyName'] ?? null;
        $fallbackAssetPropertyName = $editorOptions['fallbackAssetPropertyName'] ?? null;
        $fallbackToCleanedFilenameIfNothingIsSet = ($editorOptions['fallbackToCleanedFilenameIfNothingIsSet'] ?? true) !== false;

        if ($editor !== $expectedEditor) {
            throw new Exception('NEOSidekick EelHelper expects the editor `' . $expectedEditor . '` for the property `' . $propertyName . '`, instead `' . $editor . '` is configured.');
        }

        if ($imagePropertyName === null || !$node->hasProperty($imagePropertyName)) {
            return null;
        }

        $image = $node->getProperty($imagePropertyName);
        if ($image === null) {
            return null;
        }
        switch ($fallbackAssetPropertyName) {
            case 'title':
                if (!empty($image->getTitle())) {
                    return $image->getTitle();
                }
                break;
            case 'caption':
                if (!empty($image->getCaption())) {
                    return $image->getCaption();
                }
                break;
        }

        if ($fallbackToCleanedFilenameIfNothingIsSet) {
            $resource = $image->getResource();
            if ($resource === null) {
                return null;
            }
            $filename = str_replace('.' . $resource->getFileExtension(), '', $resource->getFilename());
            $filename = str_replace('_', ' ', $filename);
            return strtoupper(substr($filename, 0, 1)) . substr($filename, 1);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function allowsCallOfMethod($methodName): bool
    {
        return true;
    }
}

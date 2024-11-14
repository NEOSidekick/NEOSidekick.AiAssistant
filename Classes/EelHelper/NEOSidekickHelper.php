<?php

namespace NEOSidekick\AiAssistant\EelHelper;

use Exception;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\Eel\ProtectedContextAwareInterface;

class NEOSidekickHelper implements ProtectedContextAwareInterface
{
    /**
     * @throws NodeException
     * @throws Exception
     */
    public function getImageAltText(NodeInterface $node, string $propertyName): ?string
    {
        return $this->getImageText($node, $propertyName, 'NEOSidekick.AiAssistant/Inspector/Editors/ImageAltTextEditor');
    }

    /**
     * @throws NodeException
     * @throws Exception
     */
    public function getImageTitle(NodeInterface $node, string $propertyName): ?string
    {
        return $this->getImageText($node, $propertyName, 'NEOSidekick.AiAssistant/Inspector/Editors/ImageTitleEditor');
    }

    /**
     * @throws NodeException
     * @throws Exception
     */
    protected function getImageText(NodeInterface $node, string $propertyName, string $expectedEditor): ?string
    {
        if ($node->hasProperty($propertyName)) {
            return $node->getProperty($propertyName);
        }

        $propertyConfiguration = $node->getNodeType()->getFullConfiguration()['properties'][$propertyName];
        $editor = $propertyConfiguration['ui']['inspector']['editor'];
        $editorOptions = $propertyConfiguration['ui']['inspector']['editorOptions'] ?? [];
        $imagePropertyName = $editorOptions['imagePropertyName'] ?? null;
        $fallbackAssetPropertyName = $editorOptions['fallbackAssetPropertyName'] ?? null;
        $fallbackToCleanedFilenameIfNothingIsSet = $editorOptions['fallbackToCleanedFilenameIfNothingIsSet'] !== false;

        if ($editor !== $expectedEditor) {
            throw new Exception('NEOSidekick EelHelper expects the editor `' . $expectedEditor . '` for the property `' . $propertyName . '`, instead `' . $editor . '` is configured.');
        }

        if (!$node->hasProperty($imagePropertyName)) {
            return null;
        }

        $image = $node->getProperty($imagePropertyName);
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

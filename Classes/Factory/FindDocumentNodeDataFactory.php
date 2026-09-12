<?php

namespace NEOSidekick\AiAssistant\Factory;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Service\LinkingService;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Service\LanguageDimensionPresetMatcher;

/**
 * @Flow\Scope("singleton")
 */
class FindDocumentNodeDataFactory
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $nodeLinkingService;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected string $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensions;

    /**
     * @Flow\InjectConfiguration(path="defaultLanguage")
     * @var string
     */
    protected $defaultLanguage;

    /**
     * @throws NodeException
     * @throws \Neos\Flow\Security\Exception
     * @throws NodeTypeNotFoundException
     * @throws \Neos\Flow\Property\Exception
     * @throws Exception
     * @throws \Neos\Neos\Exception
     * @throws MissingActionNameException
     * @throws IllegalObjectTypeException
     */
    /**
     * @param Node|null $languageSourceNode the node the generation language is taken from, if it is
     *        not $node itself. The image module addresses a shine-through document as the container
     *        of a content node that IS a real variant, and the language of that content node is the
     *        one content has to be generated in.
     */
    public function createFromNode(Node $node, ControllerContext $controllerContext, ?Node $languageSourceNode = null): FindDocumentNodeData
    {
        $publicUri = $previewUri = $this->nodeLinkingService->createNodeUri($controllerContext, $node, null, 'html', true);
        if ($node->getContext()->getWorkspace()->getBaseWorkspace()) {
            $liveContext = $this->createContentContext('live', $node->getDimensions());
            $nodeInLiveContext = $liveContext->getNodeByIdentifier((string) $node->getNodeAggregateIdentifier());
            if ($nodeInLiveContext) {
                $publicUri = $this->nodeLinkingService->createNodeUri($controllerContext, $nodeInLiveContext, null, 'html', true);
            }
        }
        return new FindDocumentNodeData(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $node->getNodeType()->getName(),
            $publicUri,
            $previewUri,
            (array)$node->getProperties(),
            // todo language keys of the Sidekick API are not necessarily dimension values - a mapping is still missing
            $this->resolveLanguage($languageSourceNode ?? $node)
        );
    }

    /**
     * The language to generate content in for the given node.
     *
     * NodeData persists dimension values SORTED, so the first stored value of a variant that keeps
     * its full fallback chain is not its language — a Slovenian variant of the preset
     * "sl: [sl, de]" stores ["de", "sl"]. The variant is therefore matched to its configured preset
     * first, and that preset's PRIMARY configured value is used.
     *
     * On installations with a language dimension, nodes without values for it never reach this
     * factory (see {@see \NEOSidekick\AiAssistant\Service\NodeService::dimensionValuesMatchLanguageDimensionFilter}),
     * so the "defaultLanguage" fallback serves installations that use no content dimensions.
     */
    protected function resolveLanguage(Node $node): string
    {
        $dimensionValues = $node->getNodeData()->getDimensionValues();
        $storedValues = array_values($dimensionValues[$this->languageDimensionName] ?? []);
        $presetsConfiguration = $this->contentDimensions[$this->languageDimensionName]['presets'] ?? [];

        $presetIdentifier = LanguageDimensionPresetMatcher::resolvePresetIdentifier($storedValues, $presetsConfiguration);
        if ($presetIdentifier !== null) {
            $primaryPresetValue = $presetsConfiguration[$presetIdentifier]['values'][0] ?? null;
            if (is_string($primaryPresetValue) && $primaryPresetValue !== '') {
                return $primaryPresetValue;
            }
        }

        if (isset($storedValues[0]) && is_string($storedValues[0]) && $storedValues[0] !== '') {
            return $storedValues[0];
        }

        return (string)$this->defaultLanguage;
    }
}

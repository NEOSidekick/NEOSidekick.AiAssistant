<?php

namespace NEOSidekick\AiAssistant\Service;

use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Domain\Utility\NodePaths;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Media\Exception\AssetServiceException;
use Neos\Media\Exception\ThumbnailServiceException;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\NodeTypeWithImageMetadataSchemaDto;
use NEOSidekick\AiAssistant\Factory\FindDocumentNodeDataFactory;
use NEOSidekick\AiAssistant\Factory\FindImageDataFactory;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeWithImageService extends AbstractNodeService
{
    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var WorkspaceRepository
     */
    protected $workspaceRepository;

    /**
     * @Flow\Inject
     * @var FindImageDataFactory
     */
    protected $findImageDataFactory;

    /**
     * @Flow\Inject
     * @var FindDocumentNodeDataFactory
     */
    protected $findDocumentNodeDataFactory;

    /**
     * @Flow\Inject
     * @var SiteService
     */
    protected $siteService;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

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
     * @Flow\Inject
     * @var NodeTypeService
     */
    protected $nodeTypeService;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $systemLogger;

    /**
     * @param FindDocumentNodesFilter     $filter
     * @param array<FindDocumentNodeData> $findDocumentNodeDataDtos
     * @param ControllerContext           $controllerContext
     *
     * @return array
     * @throws NodeException
     * @throws NodeTypeNotFoundException
     * @throws Exception
     * @throws MissingActionNameException
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     * @throws AssetServiceException
     * @throws ThumbnailServiceException
     */
    public function findDocumentNodesHavingChildNodesWithImages(FindDocumentNodesFilter $filter, array $findDocumentNodeDataDtos, ControllerContext $controllerContext): array
    {
        $workspace = $this->workspaceRepository->findByIdentifier($filter->getWorkspace());
        $nodeTypeSchemaDtos = $this->nodeTypeService->getNodeTypesWithImageAlternativeTextOrTitleConfiguration();
        $documentNodeContextPaths = array_keys($findDocumentNodeDataDtos);

        if (!$workspace) {
            throw new InvalidArgumentException('The given workspace does not exist in the database. Please reload the page.', 1713440899886);
        }

        $workspaceChain = array_merge([$workspace], array_values($workspace->getBaseWorkspaces()));
        $contentNodesQueryBuilder = $this->createQueryBuilder($workspaceChain);

        $contentNodesQueryBuilder->andWhere('n.nodeType IN (:includeNodeTypes)');
        $contentNodesQueryBuilder->setParameter('includeNodeTypes', array_keys($nodeTypeSchemaDtos));

        $contentNodesQueryBuilder->andWhere('n.removed = :false');
        $contentNodesQueryBuilder->andWhere('n.hidden = :false');
        $contentNodesQueryBuilder->setParameter('false', false, PDO::PARAM_BOOL);

        // Only find nodes that are below or equal to the paths of the found document nodes
        $pathConstraints = $contentNodesQueryBuilder->expr()->orX();
        foreach ($documentNodeContextPaths as $contextPath) {
            $path = NodePaths::explodeContextPath($contextPath)['nodePath'];
            $pathConstraints->add($contentNodesQueryBuilder->expr()->eq('n.path', $contentNodesQueryBuilder->expr()->literal($path)));
            $pathConstraints->add($contentNodesQueryBuilder->expr()->like('n.path', $contentNodesQueryBuilder->expr()->literal($path . '%')));
        }
        $contentNodesQueryBuilder->andWhere($pathConstraints);

        // Doctrine silently DROPS the constraint above when the document list is empty (an Orx
        // without parts), which would widen the query to every site. Constrain the site
        // explicitly, exactly as NodeService::find() does.
        $currentSitePath = NodePaths::addNodePathSegment(
            SiteService::SITES_ROOT_PATH,
            $this->siteService->getSiteByHostName($controllerContext->getRequest()->getHttpRequest()->getUri()->getHost())->getNodeName()
        );
        $contentNodesQueryBuilder->andWhere($contentNodesQueryBuilder->expr()->orX(
            $contentNodesQueryBuilder->expr()->eq('n.path', ':currentSitePath'),
            $contentNodesQueryBuilder->expr()->like('n.path', ':currentSitePathWithWildcard')
        ));
        $contentNodesQueryBuilder->setParameter('currentSitePath', $currentSitePath);
        $contentNodesQueryBuilder->setParameter('currentSitePathWithWildcard', $currentSitePath . '/%');

        if (!empty($filter->getLanguageDimensionFilter())) {
            // Mirror the document query in NodeService::find(): preset identifiers are not
            // necessarily dimension values, so the constraint has to use the presets' configured
            // values. Unlike there, no exact preset match is needed afterwards - a content node is
            // only kept when its closest document aggregate is part of the already exactly
            // filtered document list below, which neutralizes the over-matching of this constraint.
            $this->addDimensionJoinConstraintsToQueryBuilder(
                $contentNodesQueryBuilder,
                [
                    $this->languageDimensionName => LanguageDimensionPresetMatcher::collectDimensionValuesOfPresets(
                        $filter->getLanguageDimensionFilter(),
                        $this->contentDimensions[$this->languageDimensionName]['presets'] ?? []
                    )
                ]
            );
        }

        $contentNodesQueryBuilder->addOrderBy('LENGTH(n.path)', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.index', 'ASC');
        $contentNodesQueryBuilder->addOrderBy('n.dimensionsHash', 'DESC');

        $items = $contentNodesQueryBuilder->getQuery()->getResult();

        $itemsReducedByWorkspaceChain = $this->reduceNodeVariantsByWorkspaces($items, $workspaceChain);

        // Container rows are built here rather than taken from the document list, so the document
        // node type filter - which the editor sets as "Restrict to page type" - has to be applied
        // to them explicitly. It only lives in the document list otherwise.
        $allowedDocumentNodeTypeNames = $this->nodeService->getNodeTypeFilter($filter);

        $result = $findDocumentNodeDataDtos;
        foreach ($itemsReducedByWorkspaceChain as $itemNodeData) {
            $context = $this->createContentContext($filter->getWorkspace(), $itemNodeData->getDimensionValues());
            $contentNode = new Node($itemNodeData, $context);

            $closestAggregateNodeData = $this->findClosestAggregate($itemNodeData);
            $closestAggregateNode = $closestAggregateNodeData !== null
                ? $context->getNode($closestAggregateNodeData->getPath())
                : null;

            $findDocumentNodeData = $closestAggregateNode !== null
                ? ($result[$closestAggregateNode->getContextPath()] ?? null)
                : null;
            if (!$findDocumentNodeData) {
                // The document can be a shine-through fallback in the selected dimension while THIS
                // content node is a real variant of it - an editor localizing a single element on an
                // otherwise inherited page. The editable unit of this module is the content node, so
                // the document serves as a container row only and its own dimension must not gate
                // it. Content nodes that are themselves fallbacks are rejected here instead:
                // generating for those would materialize a variant as a save side effect.
                if (!$this->contentNodeMatchesLanguageDimensionFilter($itemNodeData, $filter)) {
                    continue;
                }
                // Such a document is not visible in the content node's own dimension values, so the
                // container row has to be addressed through the full fallback chain of the preset.
                // NodeData::getParent() filters ancestors by the node's OWN dimension values, so
                // findClosestAggregate() cannot cross the fallback boundary either - the collection
                // and the document above a localized element only exist in the origin dimension.
                $closestAggregateNode = $this->findClosestAggregateNodeInContext(
                    $itemNodeData,
                    $this->createContentContext(
                        $filter->getWorkspace(),
                        $this->addLanguageFallbackChainToDimensionValues($itemNodeData)
                    )
                );
                if ($closestAggregateNode === null) {
                    $this->systemLogger->warning(sprintf('Nodes must at least have one aggregate ancestor, found node "%s" without.', $itemNodeData->getContextPath()));
                    continue;
                }
                if (!in_array($closestAggregateNode->getNodeType()->getName(), $allowedDocumentNodeTypeNames, true)) {
                    continue;
                }
                if (!in_array($closestAggregateNode->getNodeType()->getName(), $allowedDocumentNodeTypeNames, true)) {
                    continue;
                }
                $findDocumentNodeData = $this->findDocumentNodeDataFactory->createFromNode($closestAggregateNode, $controllerContext, $contentNode);
                $result[$closestAggregateNode->getContextPath()] = $findDocumentNodeData;
            }

            $imagePropertiesForNodeType = $nodeTypeSchemaDtos[$contentNode->getNodeType()->getName()];
            /** @var NodeTypeWithImageMetadataSchemaDto $schema */
            foreach ($imagePropertiesForNodeType as $schema) {
                if (!self::nodeMatchesPropertyFilter($itemNodeData, $filter, $schema)) {
                    continue;
                }

                $findImageData = $this->findImageDataFactory->createFromNodeAndSchema($contentNode, $schema, $controllerContext);
                if (!$findImageData) {
                    continue;
                }
                $result[$findDocumentNodeData->getNodeContextPath()] = $findDocumentNodeData->withAddedImage($findImageData);
            }
        }

        return array_filter($result, static function (FindDocumentNodeData $findDocumentNodeData) {
            return count($findDocumentNodeData->getImages()) > 0;
        });
    }

    /**
     * @param NodeData $nodeData
     *
     * @return NodeData|null
     * @throws NodeTypeNotFoundException
     */
    /**
     * Whether the content node itself belongs to one of the selected language presets. The SQL
     * constraint of the content query admits every value of the selected presets' fallback chains,
     * so this is what keeps shine-through content nodes out of the selection.
     */
    /**
     * Walks up the node's PATH inside the given context until an aggregate is found. Unlike
     * {@see findClosestAggregate}, which relies on NodeData::getParent() and therefore stays inside
     * the node's own dimension values, this crosses fallback boundaries.
     */
    protected function findClosestAggregateNodeInContext(NodeData $nodeData, Context $context): ?Node
    {
        $path = $nodeData->getPath();
        while ($path !== '' && $path !== '/') {
            $node = $context->getNode($path);
            if ($node !== null && $node->getNodeType()->isAggregate()) {
                return $node;
            }
            $path = NodePaths::getParentPath($path);
        }

        return null;
    }

    /**
     * The content node's dimension values, but with the language dimension widened to the full
     * fallback chain of the preset the node belongs to. Needed to address a document that only
     * shines through into that preset: it is invisible in the node's own values alone.
     *
     * @return array<string, array<string>>
     */
    protected function addLanguageFallbackChainToDimensionValues(NodeData $nodeData): array
    {
        $dimensionValues = $nodeData->getDimensionValues();
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return $dimensionValues;
        }

        $presetsConfiguration = $this->contentDimensions[$this->languageDimensionName]['presets'] ?? [];
        $presetIdentifier = LanguageDimensionPresetMatcher::resolvePresetIdentifier(
            $dimensionValues[$this->languageDimensionName] ?? [],
            $presetsConfiguration
        );
        $fallbackChain = $presetIdentifier !== null ? ($presetsConfiguration[$presetIdentifier]['values'] ?? null) : null;
        if (is_array($fallbackChain) && $fallbackChain !== []) {
            $dimensionValues[$this->languageDimensionName] = array_values($fallbackChain);
        }

        return $dimensionValues;
    }

    protected function contentNodeMatchesLanguageDimensionFilter(NodeData $nodeData, FindDocumentNodesFilter $filter): bool
    {
        if (!isset($this->languageDimensionName, $this->contentDimensions[$this->languageDimensionName])) {
            return true;
        }
        $selectedPresetIdentifiers = $filter->getLanguageDimensionFilter();
        if (empty($selectedPresetIdentifiers)) {
            return true;
        }

        return LanguageDimensionPresetMatcher::matchesAnyPreset(
            $nodeData->getDimensionValues()[$this->languageDimensionName] ?? [],
            $selectedPresetIdentifiers,
            $this->contentDimensions[$this->languageDimensionName]['presets'] ?? []
        );
    }

    protected function findClosestAggregate(NodeData $nodeData): ?NodeData
    {
        $currentNode = $nodeData;
        while ($currentNode !== null) {
            if ($currentNode->getNodeType()->isAggregate()) {
                return $currentNode;
            }
            $currentNode = $currentNode->getParent();
        }
        return null;
    }

    /**
     * @param NodeData                           $nodeData
     * @param FindDocumentNodesFilter            $filter
     * @param NodeTypeWithImageMetadataSchemaDto $schema
     *
     * @return bool
     * @throws NodeException
     */
    private static function nodeMatchesPropertyFilter(NodeData $nodeData, FindDocumentNodesFilter $filter, NodeTypeWithImageMetadataSchemaDto $schema): bool
    {
        $alternativeTextPropertyName = $schema->getAlternativeTextPropertyName();
        $alternativeTextPropertyValue = ($alternativeTextPropertyName && $nodeData->hasProperty($alternativeTextPropertyName)) ? $nodeData->getProperty($alternativeTextPropertyName) : null;
        $titleTextPropertyName = $schema->getTitleTextPropertyName();
        $titleTextPropertyValue = ($titleTextPropertyName && $nodeData->hasProperty($titleTextPropertyName)) ? $nodeData->getProperty($titleTextPropertyName) : null;
        $propertyValuesMatchFilter = match ($filter->getImagePropertiesFilter()) {
            'none' => true,
            'only-empty-alternative-text-or-title-text' => ($alternativeTextPropertyName && empty($alternativeTextPropertyValue)) || ($titleTextPropertyName && empty($titleTextPropertyValue)),
            'only-empty-alternative-text' => $alternativeTextPropertyName && empty($alternativeTextPropertyValue),
            'only-empty-title-text' => $titleTextPropertyName && empty($titleTextPropertyValue),
            'only-existing-alternative-text' => $alternativeTextPropertyName && !empty($alternativeTextPropertyValue),
            'only-existing-title-text' => $titleTextPropertyName && !empty($titleTextPropertyValue),
        };

        $imagePropertyIsNotEmpty = $nodeData->hasProperty($schema->getImagePropertyName()) && $nodeData->getProperty($schema->getImagePropertyName()) !== null;

        return $propertyValuesMatchFilter && $imagePropertyIsNotEmpty;
    }
}

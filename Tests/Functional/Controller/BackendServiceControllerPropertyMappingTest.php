<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Controller;

use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Property\PropertyMappingConfiguration;
use Neos\Flow\Tests\FunctionalTestCase;
use NEOSidekick\AiAssistant\Controller\BackendServiceController;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use ReflectionClass;

/**
 * Flow's PropertyMapper silently DROPS properties that are not in the initializer's
 * allowProperties() list. "baseNodeTypeFilter" used to be missing there, so neither the
 * per-module value from Root.fusion nor the empty string the frontend sends when unset ever
 * reached the DTO — the server always fell back to its own base node type.
 *
 * This maps the exact source array the backend module frontend produces (all values are
 * strings: they travel through URLSearchParams) through the controller's own configuration.
 */
class BackendServiceControllerPropertyMappingTest extends FunctionalTestCase
{
    /**
     * The source array as sent by Resources/Private/BackendModule/src/Service/NeosBackendService.ts
     *
     * @var array<string, string>
     */
    private const FRONTEND_SOURCE = [
        'filter' => 'custom',
        'workspace' => 'live',
        'seoPropertiesFilter' => 'none',
        'focusKeywordPropertyFilter' => 'none',
        'imagePropertiesFilter' => 'none',
        'languageDimensionFilter' => 'de,en',
        'nodeTypeFilter' => '',
        'baseNodeTypeFilter' => '',
    ];

    /**
     * @param array<string, string> $source
     */
    private function mapSource(array $source): FindDocumentNodesFilter
    {
        // Reflecting the controller yields Flow's PROXY class, which only inherits non-private
        // constants - so this also asserts the constant stays reachable through the proxy.
        $allowedProperties = (new ReflectionClass(BackendServiceController::class))
            ->getConstant('FIND_DOCUMENT_NODES_ALLOWED_PROPERTIES');
        self::assertIsArray($allowedProperties, 'The controller must expose its allowed-property list');

        $propertyMappingConfiguration = new PropertyMappingConfiguration();
        $propertyMappingConfiguration->skipUnknownProperties();
        $propertyMappingConfiguration->allowProperties(...$allowedProperties);

        /** @var FindDocumentNodesFilter $filter */
        $filter = $this->objectManager->get(PropertyMapper::class)
            ->convert($source, FindDocumentNodesFilter::class, $propertyMappingConfiguration);

        return $filter;
    }

    /**
     * @test
     */
    public function itMapsTheEmptyStringTheFrontendSendsWhenNoBaseNodeTypeIsSet(): void
    {
        $filter = $this->mapSource(self::FRONTEND_SOURCE);

        $this->assertNull($filter->getBaseNodeTypeFilter());
    }

    /**
     * @test
     */
    public function itMapsAConfiguredBaseNodeType(): void
    {
        $filter = $this->mapSource(['baseNodeTypeFilter' => 'Neos.Seo:SeoMetaTagsMixin'] + self::FRONTEND_SOURCE);

        $this->assertSame('Neos.Seo:SeoMetaTagsMixin', $filter->getBaseNodeTypeFilter());
    }

    /**
     * @test
     */
    public function itMapsAnAbsentBaseNodeTypeToNull(): void
    {
        $source = self::FRONTEND_SOURCE;
        unset($source['baseNodeTypeFilter']);

        $this->assertNull($this->mapSource($source)->getBaseNodeTypeFilter());
    }

    /**
     * The other filters must keep passing through unchanged.
     *
     * @test
     */
    public function itKeepsMappingTheRemainingFilterProperties(): void
    {
        $filter = $this->mapSource(self::FRONTEND_SOURCE);

        $this->assertSame('custom', $filter->getFilter());
        $this->assertSame('live', $filter->getWorkspace());
        $this->assertSame(['de', 'en'], $filter->getLanguageDimensionFilter());
        $this->assertNull($filter->getNodeTypeFilter());
    }
}

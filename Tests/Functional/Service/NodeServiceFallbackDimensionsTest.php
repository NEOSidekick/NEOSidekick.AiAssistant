<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use InvalidArgumentException;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodesFilter;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Infrastructure\ApiFacade;
use NEOSidekick\AiAssistant\Service\NodeService;
use NEOSidekick\AiAssistant\Tests\Functional\FunctionalTestCase;

/**
 * Fallback pages (a dimension serving another dimension's content through a specialization,
 * e.g. en_UK falling back to en_US) are NOT editable rows:
 * - findImportantPages() re-addresses fallback URLs to their origin dimension (deduped),
 * - real variants keep their own dimension,
 * - updatePropertiesOnNodes() rejects writes to fallback addresses — neither writing to the
 *   origin nor materializing a variant as a save side effect (which would detach the page
 *   from its fallback chain) is acceptable; variant creation stays an explicit decision.
 *
 * Uses the distribution's specialization pair (e.g. Neos.Demo: en_US → en_UK); skips when
 * the hosting distribution configures no language specialization.
 */
class NodeServiceFallbackDimensionsTest extends FunctionalTestCase
{
    protected array $siteHosts = ['example.com'];

    private ?string $generalizationValue = null;
    private ?string $specializationValue = null;

    protected function setUpContentInLive(): void
    {
        [$this->generalizationValue, $this->specializationValue] = $this->findSpecializationPair();
        if ($this->generalizationValue === null) {
            return; // tests will skip
        }

        // The site node exists in the primary language; add a variant in the generalization
        // language so pages can live below it there.
        $siteNode = $this->getNodeByPath('/sites/example');
        if ($this->generalizationValue !== $this->primaryLanguage()) {
            $this->createLanguageVariant($siteNode, $this->generalizationValue);
        }

        // A page existing ONLY in the generalization — covered in the specialization via fallback
        $siteNodeInGeneralization = $this->getNodeByPath('/sites/example', 'live', $this->generalizationValue);
        $this->createPageWithImageNodes($siteNodeInGeneralization, 'fallback-page', 'Fallback Page', ['image1.jpg'], $this->generalizationValue);

        // A page with a REAL variant in the specialization
        $variantPage = $this->createPageWithImageNodes($siteNodeInGeneralization, 'variant-page', 'Variant Page', ['image1.jpg'], $this->generalizationValue);
        $this->createLanguageVariant($variantPage, $this->specializationValue);
    }

    /**
     * @return array{0: ?string, 1: ?string} [generalization, specialization] language values
     */
    private function findSpecializationPair(): array
    {
        $dimensionId = $this->languageDimensionId();
        if ($dimensionId === null) {
            return [null, null];
        }
        $dimension = $this->contentRepository->getContentDimensionSource()->getDimension($dimensionId);
        foreach ($dimension->values as $value) {
            if ($value->specializationDepth->value === 1) {
                foreach ($dimension->values as $generalization) {
                    if ($generalization->specializationDepth->value === 0
                        && $dimension->getGeneralization($value)?->value === $generalization->value) {
                        return [$generalization->value, $value->value];
                    }
                }
            }
        }
        return [null, null];
    }

    private function requireSpecializationPair(): void
    {
        if ($this->generalizationValue === null || $this->specializationValue === null) {
            $this->markTestSkipped('This test needs a language dimension with a specialization (fallback) pair.');
        }
    }

    /**
     * @test
     */
    public function importantPagesReAddressesFallbackUrlsToTheirOrigin(): void
    {
        $this->requireSpecializationPair();

        $fallbackUrl = 'https://example.com/' . $this->languageUriSegment($this->specializationValue) . '/fallback-page' . $this->getUriPathSuffix();
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)->disableOriginalConstructor()->getMock();
        $apiFacadeMock->method('getMostRelevantInternalSeoUrisByHosts')->willReturn([$fallbackUrl]);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $filter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            languageDimensionFilter: $this->generalizationValue . ',' . $this->specializationValue
        );
        $foundNodes = $nodeService->findImportantPages($filter, $this->createControllerContextForDomain('example.com'), 'de');

        $this->assertCount(1, $foundNodes, 'The fallback URL must resolve to exactly one (origin-addressed) row');
        $originKey = $this->addressForPath('/sites/example/fallback-page', 'live', $this->generalizationValue);
        $this->assertArrayHasKey($originKey, $foundNodes, 'The row must be addressed in the ORIGIN dimension');
        $this->assertSame($this->generalizationValue, reset($foundNodes)->getLanguage(), 'The row must be labeled with the origin language');
    }

    /**
     * @test
     */
    public function importantPagesKeepsRealVariantsInTheirOwnDimension(): void
    {
        $this->requireSpecializationPair();

        $variantUrl = 'https://example.com/' . $this->languageUriSegment($this->specializationValue) . '/variant-page' . $this->getUriPathSuffix();
        $apiFacadeMock = $this->getMockBuilder(ApiFacade::class)->disableOriginalConstructor()->getMock();
        $apiFacadeMock->method('getMostRelevantInternalSeoUrisByHosts')->willReturn([$variantUrl]);

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $this->inject($nodeService, 'apiFacade', $apiFacadeMock);

        $filter = new FindDocumentNodesFilter(
            filter: 'important-pages',
            workspace: 'live',
            languageDimensionFilter: $this->generalizationValue . ',' . $this->specializationValue
        );
        $foundNodes = $nodeService->findImportantPages($filter, $this->createControllerContextForDomain('example.com'), 'de');

        $this->assertCount(1, $foundNodes);
        $variantKey = $this->addressForPath('/sites/example/variant-page', 'live', $this->specializationValue);
        $this->assertArrayHasKey($variantKey, $foundNodes, 'A real variant must stay addressed in its own dimension');
    }

    /**
     * @test
     */
    public function updateRejectsWritesToFallbackAddresses(): void
    {
        $this->requireSpecializationPair();

        $node = $this->getNodeByPath('/sites/example/fallback-page', 'live', $this->generalizationValue);
        $fallbackAddress = NodeAddress::create(
            $this->contentRepository->id,
            WorkspaceName::forLive(),
            $this->dimensionSpacePoint($this->specializationValue),
            $node->aggregateId
        )->toJson();

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1752060000001);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => $fallbackAddress,
                'properties' => ['focusKeyword' => 'must-not-be-written'],
                'images' => [],
            ]),
        ]);
    }

    /**
     * @test
     */
    public function updateAcceptsWritesToRealVariants(): void
    {
        $this->requireSpecializationPair();

        $variantNode = $this->getNodeByPath('/sites/example/variant-page', 'live', $this->specializationValue);
        $variantAddress = NodeAddress::create(
            $this->contentRepository->id,
            WorkspaceName::forLive(),
            $this->dimensionSpacePoint($this->specializationValue),
            $variantNode->aggregateId
        )->toJson();

        /** @var NodeService $nodeService */
        $nodeService = $this->objectManager->get(NodeService::class);
        $nodeService->updatePropertiesOnNodes([
            UpdateNodeProperties::fromArray([
                'nodeContextPath' => $variantAddress,
                'properties' => ['focusKeyword' => 'variant-keyword'],
                'images' => [],
            ]),
        ]);

        $updated = $this->getNodeByPath('/sites/example/variant-page', 'live', $this->specializationValue);
        $this->assertSame('variant-keyword', $updated->getProperty('focusKeyword'));
        // ...and the generalization stays untouched
        $generalization = $this->getNodeByPath('/sites/example/variant-page', 'live', $this->generalizationValue);
        $this->assertNull($generalization->getProperty('focusKeyword'));
    }
}

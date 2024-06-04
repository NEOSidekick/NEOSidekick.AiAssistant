<?php

namespace NEOSidekick\AiAssistant\Factory;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Controller\ControllerContext;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Utility\Arrays;
use NEOSidekick\AiAssistant\Dto\FindDocumentNodeData;

/**
 * @Flow\Scope("singleton")
 */
class FindDocumentNodeDataFactory
{
    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\InjectConfiguration(path="languageDimensionName")
     * @var string
     */
    protected string $languageDimensionName;

    /**
     * @throws NodeException
     * @throws \Neos\Flow\Security\Exception
     * @throws NodeTypeNotFoundException
     * @throws Exception
     * @throws \Neos\Flow\Property\Exception
     * @throws MissingActionNameException
     * @throws IllegalObjectTypeException
     */
    public function createFromNode(Node $node, ControllerContext $controllerContext): FindDocumentNodeData
    {
        $site = $this->findSiteForNode($node);
        if (!$site) {
            throw new InvalidArgumentException(sprintf('Node "%s" has no nearby site', $node->findNodePath()));
        }

        $publicUri = $this->buildNodeUriWithBestMatchingHost($site, $controllerContext, $node);

        return new FindDocumentNodeData(
            sprintf('%s-%s', $node->getNodeData()->getIdentifier(), $node->getNodeData()->getDimensionsHash()),
            $node->getContextPath(),
            $node->getNodeType()->getName(),
            $publicUri,
            (array)$node->getProperties(),
            // todo inspect [0] syntax... maybe we also need a mapping? replace default value and/or discuss setup with and without language dimensions
            Arrays::getValueByPath($node->getNodeData()->getDimensionValues(), $this->languageDimensionName . '.0') ?: 'de'
        );
    }

    /**
     * @param Node $node
     *
     * @return Site|null
     */
    protected function findSiteForNode(Node $node): ?Site
    {
        $nodePathWithoutApex = str_replace('/sites/', '', (string)$node->findNodePath());
        $nodePathWithoutApexAsSegmentsArray = explode('/', $nodePathWithoutApex);
        return $this->siteRepository->findOneByNodeName($nodePathWithoutApexAsSegmentsArray[0]);
    }

    /**
     * @param Site              $site
     * @param ControllerContext $controllerContext
     * @param Node              $node
     *
     * @return Uri|string
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws MissingActionNameException
     */
    protected static function buildNodeUriWithBestMatchingHost(
        Site $site,
        ControllerContext $controllerContext,
        Node $node
    ): string|Uri {
        $firstActiveDomain = $site->getFirstActiveDomain();
        // If there is no active domain on the closest site,
        // we try to get an absolute uri from the routing service
        // which uses fallback patterns
        if (!$firstActiveDomain) {
            return self::buildNodeUri($controllerContext, $node, true);
        }

        $currentRequestUri = $controllerContext->getRequest()->getHttpRequest()->getUri();
        return (new Uri())
            ->withScheme($firstActiveDomain->getScheme() ?: $currentRequestUri->getScheme())
            ->withHost($firstActiveDomain->getHostname())
            ->withPath(self::buildNodeUri($controllerContext, $node));
    }

    /**
     * @param ControllerContext $controllerContext
     * @param Node              $node
     * @param bool              $absolute
     *
     * @return string
     * @throws Exception
     * @throws IllegalObjectTypeException
     * @throws MissingActionNameException
     */
    protected static function buildNodeUri(ControllerContext $controllerContext, Node $node, bool $absolute = false): string
    {
        $uriBuilder = clone $controllerContext->getUriBuilder();
        $action = $node->getContext()->getWorkspace()->isPublicWorkspace() && !$node->isHidden() ? 'show' : 'preview';
        return $uriBuilder
            ->reset()
            ->setFormat('html')
            ->setCreateAbsoluteUri($absolute)
            ->uriFor($action, ['node' => $node], 'Frontend\Node', 'Neos.Neos');
    }
}

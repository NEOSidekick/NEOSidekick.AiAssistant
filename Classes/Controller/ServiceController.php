<?php
namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\JsonView;
use Neos\Flow\Property\PropertyMappingConfiguration;
use NEOSidekick\AiAssistant\Domain\Repository\AccessTokenRepository;
use NEOSidekick\AiAssistant\Domain\Model\AccessToken;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Service\NodeService;

class ServiceController extends ActionController
{
    /**
     * @Flow\Inject
     * @var AccessTokenRepository
     */
    protected $accessTokenRepository;

    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    protected $supportedMediaTypes = array('application/json');

    protected $defaultViewObjectName = JsonView::class;

    public function initializeUpdateNodePropertiesAction(): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->arguments->getArgument('updateItems')
            ->getPropertyMappingConfiguration()
            ->skipUnknownProperties()
            ->forProperty(PropertyMappingConfiguration::PROPERTY_PATH_PLACEHOLDER)
            ->allowProperties(
                'nodeContextPath',
                'properties',
                'images'
            );
    }

    /**
     * @param array<UpdateNodeProperties> $updateItems
     * @param string $token
     *
     * @return void
     * @throws StopActionException
     * @Flow\SkipCsrfProtection
     */
    public function updateNodePropertiesAction(array $updateItems, string $token): void
    {
        if (empty($token)) {
            $this->throwStatus(401); // Unauthorized
        }

        /** @var AccessToken $accessToken */
        $accessToken = $this->accessTokenRepository->findByIdentifier($token);

        if (!$accessToken instanceof AccessToken || !$accessToken->isValid()) {
            $this->throwStatus(403); // Forbidden
        }

        $this->nodeService->updatePropertiesOnNodes($updateItems);
        $this->view->assign('value',array_map(static fn(UpdateNodeProperties $item) => $item->jsonSerialize(), $updateItems));
    }
}

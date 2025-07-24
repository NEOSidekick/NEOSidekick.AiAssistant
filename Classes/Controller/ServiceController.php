<?php
namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\JsonView;
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

    /**
     * @param string $nodeContextPath
     * @param string $propertyName
     * @param string $token
     *
     * @return void
     * @throws StopActionException
     * @Flow\SkipCsrfProtection
     */
    public function updateNodePropertyAction(string $nodeContextPath, string $propertyName, string $token): void
    {
        if (empty($token)) {
            $this->throwStatus(401); // Unauthorized
        }

        /** @var AccessToken $accessToken */
        $accessToken = $this->accessTokenRepository->findByIdentifier($token);

        if (!$accessToken instanceof AccessToken || !$accessToken->isValid()) {
            $this->throwStatus(403); // Forbidden
        }

        // Get and decode the JSON request body
        $requestContent = $this->request->getHttpRequest()->getBody();
        $decodedBody = json_decode($requestContent, true);

        // Validate the JSON structure
        if ($decodedBody === null) {
            $this->throwStatus(400, 'Invalid JSON format'); // Bad Request
        }

        if (!isset($decodedBody['status']) || $decodedBody['status'] !== 'success') {
            $this->throwStatus(400, 'Invalid request format: missing or invalid status'); // Bad Request
        }

        if (!isset($decodedBody['data']['message']['message']) || !is_string($decodedBody['data']['message']['message'])) {
            $this->throwStatus(400, 'Invalid request format: missing or invalid message'); // Bad Request
        }

        $newPropertyValue = $decodedBody['data']['message']['message'];

        // Create properties array with the single property to update
        $propertiesToUpdate = [$propertyName => $newPropertyValue];

        // Create a single UpdateNodeProperties DTO
        $updateItem = new UpdateNodeProperties($nodeContextPath, $propertiesToUpdate, []);

        // Update the node property
        $this->nodeService->updatePropertiesOnNodes([$updateItem]);

        // Assign the serialized DTO to the view
        $this->view->assign('value', $updateItem->jsonSerialize());
    }
}

<?php
namespace NEOSidekick\AiAssistant\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\View\JsonView;
use NEOSidekick\AiAssistant\Dto\UpdateNodeProperties;
use NEOSidekick\AiAssistant\Service\NodeService;

class WebhookController extends ActionController
{
    /**
     * @Flow\Inject
     * @var NodeService
     */
    protected $nodeService;

    protected $supportedMediaTypes = array('application/json');

    protected $defaultViewObjectName = JsonView::class;

    /**
     * Process batch responses from the Sidekick AI service
     *
     * @return void
     * @throws StopActionException
     */
    public function processSidekickResponseAction(): void
    {
        $requestContent = $this->request->getHttpRequest()->getContent();
        $results = json_decode($requestContent, true);

        if ($results === null || !is_array($results)) {
            $this->throwStatus(400, 'Invalid JSON format or not an array'); // Bad Request
        }

        $updatesCollection = [];

        foreach ($results as $result) {
            if (!isset($result['status']) || $result['status'] !== 'success' || !isset($result['request_id']) || !isset($result['data']['message']['message'])) {
                // Optionally log the malformed result item
                continue;
            }

            $requestIdParts = explode('#', $result['request_id']);
            if (count($requestIdParts) !== 2) {
                // Optionally log the invalid request_id format
                continue;
            }
            [$nodeContextPath, $propertyName] = $requestIdParts;

            $newPropertyValue = $result['data']['message']['message'];
            $propertiesToUpdate = [$propertyName => $newPropertyValue];
            $updatesCollection[] = new UpdateNodeProperties($nodeContextPath, $propertiesToUpdate, []);
        }

        if (!empty($updatesCollection)) {
            $this->nodeService->updatePropertiesOnNodes($updatesCollection);
        }

        $this->view->assign('value', ['status' => 'success', 'processedItems' => count($updatesCollection)]);
    }
}

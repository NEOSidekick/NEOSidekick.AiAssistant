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

    public function initializeProcessSidekickResponseAction(): void
    {
        if ($this->request->getHttpRequest()->getMethod() === 'OPTIONS') {
            $this->response->addHttpHeader('Allow', 'POST');
            $this->response->setStatusCode(204);
            throw new StopActionException();
        }
    }

    /**
     * Process batch responses from the Sidekick AI service
     *
     * @return void
     * @throws StopActionException
     * @Flow\SkipCsrfProtection
     */
    public function processSidekickResponseAction(): void
    {
        $payload = $this->request->getHttpRequest()->getParsedBody();

        if (!is_array($payload) || !isset($payload['status']) || $payload['status'] !== 'success' || !isset($payload['data']) || !is_array($payload['data'])) {
            $this->throwStatus(400, 'Invalid JSON payload format'); // Bad Request
        }

        $results = $payload['data'];
        $groupedUpdates = [];

        foreach ($results as $result) {
            if (!isset($result['request_id']) || !isset($result['message']['message'])) {
                // Optionally log the malformed result item
                continue;
            }

            $requestIdParts = explode('#', $result['request_id']);
            if (count($requestIdParts) !== 2) {
                // Optionally log the invalid request_id format
                continue;
            }
            [$nodeContextPath, $propertyName] = $requestIdParts;

            // This is a quick fix because focus_keyword_generator
            // will give us an array with five possible focus keywords.
            // In any case if we get multiple options, we can only pick one.
            if (is_array($result['message']['message'])) {
                if (empty($result['message']['message'])) {
                    continue;
                }
                $newPropertyValue = reset($result['message']['message']);
            } else {
                $newPropertyValue = $result['message']['message'];
            }
            $groupedUpdates[$nodeContextPath][$propertyName] = $newPropertyValue;
        }

        $updatesCollection = [];
        foreach ($groupedUpdates as $nodeContextPath => $properties) {
            $updatesCollection[] = new UpdateNodeProperties($nodeContextPath, $properties, []);
        }

        if (!empty($updatesCollection)) {
            $this->nodeService->updatePropertiesOnNodes($updatesCollection);
        }

        $this->view->assign('value', ['status' => 'success', 'processedItems' => count($updatesCollection)]);
    }
}

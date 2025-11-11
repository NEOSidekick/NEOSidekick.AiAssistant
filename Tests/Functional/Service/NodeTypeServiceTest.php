<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\Flow\Tests\FunctionalTestCase;
use NEOSidekick\AiAssistant\Service\NodeTypeService;

class NodeTypeServiceTest extends FunctionalTestCase
{
    public function testGetNodeTypesMatchingConfiguration(): void
    {
        $nodeTypeService = $this->objectManager->get(NodeTypeService::class);
        $nodeTypes = $nodeTypeService->getNodeTypesMatchingConfiguration();
        $this->assertCount(1, $nodeTypes);
    }
}

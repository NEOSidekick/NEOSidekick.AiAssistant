<?php

namespace NEOSidekick\AiAssistant\Tests\Functional\Service;

use Neos\Flow\Tests\FunctionalTestCase;
use NEOSidekick\AiAssistant\Service\NodeTypeService;
use PHPUnit\Framework\Attributes\Test;

class NodeTypeServiceTest extends FunctionalTestCase
{
    /**
     * @return void
     */
    #[Test]
    public function itFindsTestingNodeTypeWithImageAlternativeTextOrTitleConfiguration(): void
    {
        $nodeTypeService = $this->objectManager->get(NodeTypeService::class);
        $nodeTypes = $nodeTypeService->getNodeTypesWithImageAlternativeTextOrTitleConfiguration();
        $this->assertArrayHasKey('NEOSidekick.AiAssistant.Testing:Image', $nodeTypes);
    }
}

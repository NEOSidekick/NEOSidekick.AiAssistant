<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use Neos\Flow\Mvc\ActionResponse;
use Neos\Neos\Service\UserService;
use NEOSidekick\AiAssistant\Controller\NodeTreeSchemaApiController;
use NEOSidekick\AiAssistant\Exception\InvalidContentDimensionsException;
use NEOSidekick\AiAssistant\Service\NodeTreeExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Dimensions the site does not have are the caller's mistake: the node tree endpoint answers them
 * with a 400 that carries the reason, not with "node not found" or a server error.
 */
class NodeTreeSchemaApiControllerDimensionsTest extends TestCase
{
    /** @test */
    public function unknownDimensionsAreABadRequestWithTheReason(): void
    {
        $treeExtractor = $this->createMock(NodeTreeExtractor::class);
        $treeExtractor->method('extract')->willThrowException(
            new InvalidContentDimensionsException('["es"] is not a configured preset of the "language" dimension.')
        );
        $userService = $this->createMock(UserService::class);
        $userService->method('getPersonalWorkspaceName')->willReturn('user-editor');
        $response = new ActionResponse();

        $controller = $this->getMockBuilder(NodeTreeSchemaApiController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        foreach (['response' => $response, 'treeExtractor' => $treeExtractor, 'userService' => $userService] as $name => $value) {
            $property = new ReflectionProperty(NodeTreeSchemaApiController::class, $name);
            $property->setAccessible(true);
            $property->setValue($controller, $value);
        }

        $body = $controller->getNodeTreeAction('page-id', 'live', '{"language":["es"]}');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Bad Request', 'message' => '["es"] is not a configured preset of the "language" dimension.'],
            json_decode($body, true, 512, JSON_THROW_ON_ERROR)
        );
    }
}

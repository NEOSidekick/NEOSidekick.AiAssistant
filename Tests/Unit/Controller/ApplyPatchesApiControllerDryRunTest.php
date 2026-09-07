<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Controller;

use Neos\Flow\Mvc\ActionResponse;
use NEOSidekick\AiAssistant\Controller\ApplyPatchesApiController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * `dryRun` was removed in 3.1.0. A backend that still sends `dryRun: true` must be refused before
 * anything is written; `false` or an absent key is ignored.
 */
class ApplyPatchesApiControllerDryRunTest extends TestCase
{
    /** @test */
    public function aTruthyDryRunIsRefusedWithTheFailureBody(): void
    {
        $response = new ActionResponse();
        $body = $this->validateRequestData($response, ['patches' => [['operation' => 'deleteNode', 'nodeId' => 'x']], 'dryRun' => true]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            [
                'success' => false,
                'error' => [
                    'message' => 'dry-run is no longer supported; apply the batch, a failure writes nothing',
                    'patchIndex' => 0,
                    'operation' => 'batch',
                    'nodeId' => null,
                    'ref' => null,
                ],
                'rollbackPerformed' => false,
            ],
            json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /** @test */
    public function aFalseOrAbsentDryRunIsIgnored(): void
    {
        $patches = ['patches' => [['operation' => 'deleteNode', 'nodeId' => 'x']]];

        self::assertNull($this->validateRequestData(new ActionResponse(), $patches + ['dryRun' => false]));
        self::assertNull($this->validateRequestData(new ActionResponse(), $patches));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateRequestData(ActionResponse $response, array $data): ?string
    {
        $controller = $this->getMockBuilder(ApplyPatchesApiController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $property = new ReflectionProperty(ApplyPatchesApiController::class, 'response');
        $property->setAccessible(true);
        $property->setValue($controller, $response);

        $method = new ReflectionMethod(ApplyPatchesApiController::class, 'validateRequestData');
        $method->setAccessible(true);

        return $method->invoke($controller, $data);
    }
}

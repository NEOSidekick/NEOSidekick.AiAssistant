<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Unit\Dto\Patch;

use NEOSidekick\AiAssistant\Dto\Patch\PatchError;
use PHPUnit\Framework\TestCase;

/**
 * `error.nodeId` is a node id or null, never a batch-local alias; the alias goes to `error.ref` as sent.
 */
class PatchErrorTest extends TestCase
{
    /** @test */
    public function aRefAnchorPassedAsNodeIdIsMovedToRef(): void
    {
        $error = new PatchError('boom', 3, 'createNode', '$acc/main');

        self::assertNull($error->getNodeId());
        self::assertSame('$acc/main', $error->getRef());
        self::assertSame(
            ['message' => 'boom', 'patchIndex' => 3, 'operation' => 'createNode', 'nodeId' => null, 'ref' => '$acc/main'],
            $error->jsonSerialize()
        );
    }

    /** @test */
    public function aNodeIdStaysANodeId(): void
    {
        $error = new PatchError('boom', 0, 'updateNode', 'a1b2c3d4-0000-0000-0000-000000000001');

        self::assertSame('a1b2c3d4-0000-0000-0000-000000000001', $error->getNodeId());
        self::assertNull($error->getRef());
        self::assertNull((new PatchError('boom', 0, 'unknown'))->getRef());
    }
}

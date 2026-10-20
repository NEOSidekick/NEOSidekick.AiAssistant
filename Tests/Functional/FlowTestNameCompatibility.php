<?php

declare(strict_types=1);

namespace NEOSidekick\AiAssistant\Tests\Functional;

/**
 * Flow 9.1.2's FunctionalTestCase::setUp() names the test through getName(), which PHPUnit 10 removed,
 * whenever an earlier test left a session started; without this every later test of the run errors.
 */
trait FlowTestNameCompatibility
{
    public function getName(): string
    {
        return $this->name();
    }
}

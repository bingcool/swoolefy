<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\MainManager;

/**
 * fork 失败必须从 Registry 回滚，避免表里有对象、进程没起来。
 */
final class ProcessSupervisorForkRollbackTest extends TestCase
{
    public function testDoForkFailureRemovesWorkerFromRegistry(): void
    {
        $manager = new class extends MainManager {
            public function __construct()
            {
            }

            public function handleWorkerException(\Throwable $throwable): void
            {
            }

            public function logInfo($msg): void
            {
            }

            public function getMasterPid()
            {
                return 1;
            }

            public function swooleEventAdd(?AbstractBaseWorker $currentProcess = null)
            {
            }

            protected function fmtWriteInfo($msg)
            {
            }

            protected function fmtWriteError($msg)
            {
            }
        };

        $manager->getSupervisor()->doFork(
            'ThisProcessClassDoesNotExist',
            'rollback-demo',
            0,
            [],
            []
        );

        $this->assertFalse($manager->getRegistry()->hasWorker('rollback-demo', 0));
        $this->assertFalse($manager->getRegistry()->hasWorkerGroup('rollback-demo'));
    }
}

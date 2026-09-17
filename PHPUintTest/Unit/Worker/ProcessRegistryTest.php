<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Swoolefy\Worker\Process\ProcessRegistry;

/**
 * ProcessRegistry：add/get/remove；fork 失败回滚由 Supervisor 调用 removeWorker 覆盖。
 */
final class ProcessRegistryTest extends TestCase
{
    public function testKeyIsMd5OfProcessName(): void
    {
        $this->assertSame(md5('order-sync'), ProcessRegistry::key('order-sync'));
    }

    public function testSetGetRemoveWorkerAndList(): void
    {
        $registry = new ProcessRegistry();
        $worker = new \stdClass();
        $registry->setList('p1', ['process_name' => 'p1', 'process_worker_num' => 1]);
        $registry->setWorker('p1', 0, $worker);

        $this->assertTrue($registry->hasList('p1'));
        $this->assertTrue($registry->hasWorker('p1', 0));
        $this->assertSame($worker, $registry->getWorker('p1', 0));
        $this->assertSame(1, $registry->countWorkers());

        $registry->removeWorker('p1', 0);
        $this->assertFalse($registry->hasWorkerGroup('p1'));
        $this->assertNull($registry->getWorkersByName('p1'));

        $registry->unsetList('p1');
        $this->assertFalse($registry->hasList('p1'));
    }

    public function testFindByPidAndStoppingDynamic(): void
    {
        $registry = new ProcessRegistry();
        $worker = new class {
            public function getPid(): int
            {
                return 4242;
            }
        };
        $registry->setWorker('dyn', 1, $worker);
        $this->assertSame($worker, $registry->findByPid(4242));
        $this->assertNull($registry->findByPid(1));

        $registry->markStoppingDynamic(4242, 'dyn');
        $this->assertTrue($registry->isStoppingDynamicPid(4242));
        $this->assertTrue($registry->hasStoppingDynamicProcess('dyn'));
        $registry->unmarkStoppingDynamic(4242);
        $this->assertFalse($registry->hasStoppingDynamicProcess('dyn'));
    }

    public function testReplaceListsAndWorkersForTests(): void
    {
        $registry = new ProcessRegistry();
        $key = ProcessRegistry::key('p');
        $registry->replaceLists([$key => ['process_name' => 'p']]);
        $registry->replaceWorkers([$key => [0 => new \stdClass()]]);
        $this->assertSame(1, $registry->countWorkers());
        $this->assertSame('p', $registry->getList('p')['process_name']);
    }
}

<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Worker;

use PhpUintTest\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Swoolefy\Worker\AbstractBaseWorker;
use Swoolefy\Worker\MainManager;

/**
 * 阶段一 P0-3.2（审计项 2）：动态进程类型标记与缩容计数时机。
 * 目标：扩容为 dynamic、缩容只停 dynamic、发信号不提前减计数、reap 后计数正确且幂等。
 */
final class DynamicProcessLifecycleTest extends TestCase
{
    /**
     * 测 forkNewProcess 第 6 参 processType 默认值为 PROCESS_STATIC_TYPE。
     * 对应问题：未显式传类型时须保持静态进程语义，避免破坏现有 fork 入口。
     */
    public function testForkNewProcessDefaultsToStaticType(): void
    {
        $method = new ReflectionMethod(MainManager::class, 'forkNewProcess');
        $params = $method->getParameters();
        $this->assertGreaterThanOrEqual(6, count($params));
        $this->assertSame('processType', $params[5]->getName());
        $this->assertTrue($params[5]->isDefaultValueAvailable());
        $this->assertSame(AbstractBaseWorker::PROCESS_STATIC_TYPE, $params[5]->getDefaultValue());
    }

    /**
     * 测 createDynamicProcess 调用 fork 时传入 PROCESS_DYNAMIC_TYPE。
     * 对应问题：动态扩容若写死 static，缩容无法识别/只停动态进程会失效。
     */
    public function testCreateDynamicProcessPassesDynamicType(): void
    {
        $manager = $this->newManagerStub();
        $processName = 'p0-dynamic-demo';
        $key = md5($processName);
        $this->setPrivate($manager, 'processLists', [
            $key => [
                'process_name' => $processName,
                'process_class' => 'DemoProcess',
                'process_worker_num' => 1,
                'dynamic_process_worker_num' => 0,
                'dynamic_process_destroying' => false,
                'async' => true,
                'args' => ['max_process_num' => 10],
                'extend_data' => [],
                'enable_coroutine' => true,
            ],
        ]);
        $this->setPrivate($manager, 'processWorkers', [
            $key => [
                0 => $this->fakeProcess($processName, 0, 1001, false),
            ],
        ]);

        $manager->createDynamicProcess($processName, 1);

        $this->assertCount(1, $manager->forkCalls);
        $this->assertSame(AbstractBaseWorker::PROCESS_DYNAMIC_TYPE, $manager->forkCalls[0]['processType']);
        $this->assertTrue($this->fakeProcess($processName, 1, 2002, true)->isDynamicProcess());
    }

    /**
     * 测缩容只向 dynamic 发退出信号、发信号后计数不变、重复停止幂等、reap 后计数减一次且不负。
     * 对应问题：误停 static、发信号即减计数导致扩缩容窗口误判、重复回收产生负数计数。
     */
    public function testDestroyDynamicOnlyStopsDynamicAndDefersCountUntilReap(): void
    {
        $manager = $this->newManagerStub();
        $processName = 'p0-destroy-demo';
        $key = md5($processName);
        $static = $this->fakeProcess($processName, 0, 3001, false);
        $dynamic = $this->fakeProcess($processName, 1, 3002, true);

        $this->setPrivate($manager, 'processLists', [
            $key => [
                'process_name' => $processName,
                'dynamic_process_worker_num' => 1,
                'dynamic_process_destroying' => false,
            ],
        ]);
        $this->setPrivate($manager, 'processWorkers', [
            $key => [
                0 => $static,
                1 => $dynamic,
            ],
        ]);
        $this->setPrivate($manager, 'stoppingDynamicProcesses', []);

        $manager->destroyDynamicProcess($processName, 1);

        $this->assertCount(1, $manager->writes);
        $this->assertSame($processName, $manager->writes[0][0]);
        $this->assertSame(AbstractBaseWorker::WORKERFY_PROCESS_EXIT_FLAG, $manager->writes[0][1]);
        $this->assertSame(1, $manager->writes[0][2]);
        $this->assertSame(1, $this->getPrivate($manager, 'processLists')[$key]['dynamic_process_worker_num']);
        $this->assertTrue($this->getPrivate($manager, 'processLists')[$key]['dynamic_process_destroying']);
        $this->assertSame([$dynamic->getPid() => $processName], $this->getPrivate($manager, 'stoppingDynamicProcesses'));

        // 重复停止同一 PID：幂等，不再发信号、不改计数
        $manager->destroyDynamicProcess($processName, 1);
        $this->assertCount(1, $manager->writes);
        $this->assertSame(1, $this->getPrivate($manager, 'processLists')[$key]['dynamic_process_worker_num']);

        // 模拟 SIGCHLD reap：先移除进程，再重算计数
        $workers = $this->getPrivate($manager, 'processWorkers');
        unset($workers[$key][1]);
        $this->setPrivate($manager, 'processWorkers', $workers);
        $stopping = $this->getPrivate($manager, 'stoppingDynamicProcesses');
        unset($stopping[$dynamic->getPid()]);
        $this->setPrivate($manager, 'stoppingDynamicProcesses', $stopping);

        $this->assertSame(0, $manager->storageDynamicProcessNum($processName));
        $this->assertSame(0, $this->getPrivate($manager, 'processLists')[$key]['dynamic_process_worker_num']);

        // 再次 storage 不会变成负数
        $this->assertSame(0, $manager->storageDynamicProcessNum($processName));
    }

    /**
     * @return MainManager&object{forkCalls:list<array{processType:int}>,writes:list<array{0:string,1:mixed,2:int}>}
     */
    private function newManagerStub(): MainManager
    {
        return new class extends MainManager {
            /** @var list<array{processType:int}> */
            public array $forkCalls = [];
            /** @var list<array{0:string,1:mixed,2:int}> */
            public array $writes = [];

            public function __construct()
            {
                // 跳过父构造，避免依赖 APP_PATH / 协程配置
            }

            protected function forkNewProcess(
                $processClass,
                $processName,
                $workerId,
                $args = [],
                $extendData = [],
                int $processType = AbstractBaseWorker::PROCESS_STATIC_TYPE
            ): void {
                $this->forkCalls[] = [
                    'processClass' => $processClass,
                    'processName' => $processName,
                    'workerId' => $workerId,
                    'processType' => $processType,
                ];
            }

            public function writeByProcessName(string $processName, $data, int $processWorkerId = 0)
            {
                $this->writes[] = [$processName, $data, $processWorkerId];
            }

            public function isMasterExiting(): bool
            {
                return false;
            }

            protected function fmtWriteInfo($msg)
            {
                // 单测静默
            }

            protected function fmtWriteError($msg)
            {
                // 单测静默
            }
        };
    }

    private function fakeProcess(string $name, int $workerId, int $pid, bool $dynamic): object
    {
        return new class ($name, $workerId, $pid, $dynamic) {
            public function __construct(
                private string $name,
                private int $workerId,
                private int $pid,
                private bool $dynamic,
            ) {
            }

            public function isDynamicProcess(): bool
            {
                return $this->dynamic;
            }

            public function getPid(): int
            {
                return $this->pid;
            }

            public function getProcessName(): string
            {
                return $this->name;
            }

            public function getProcessWorkerId(): int
            {
                return $this->workerId;
            }
        };
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(MainManager::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    private function getPrivate(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty(MainManager::class, $property);
        $ref->setAccessible(true);

        return $ref->getValue($object);
    }
}

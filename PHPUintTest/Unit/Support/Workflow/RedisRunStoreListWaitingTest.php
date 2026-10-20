<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Support\Workflow;

use PHPUintTest\TestCase;
use ReflectionMethod;
use Swoolefy\Support\Workflow\Engine\RedisRunStore;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowRuntimeException;
use Swoolefy\Support\Workflow\WorkflowRegistry;

/**
 * listWaiting 对单条坏数据降级，对 Redis 基础设施错误 fail-fast。
 */
final class RedisRunStoreListWaitingTest extends TestCase
{
    public function testSkippableWaitingListErrors(): void
    {
        $store = new RedisRunStore(
            static fn () => throw new WorkflowException('no redis in test'),
            new WorkflowRegistry(),
        );
        $method = new ReflectionMethod(RedisRunStore::class, 'isSkippableWaitingListError');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $store,
            new WorkflowRuntimeException('Corrupt workflow run payload [run-1]'),
        ));
        $this->assertTrue($method->invoke(
            $store,
            new WorkflowException('Cannot hydrate run run-1: workflow demo@1.0.0 is not registered'),
        ));
        $this->assertTrue($method->invoke($store, new \ValueError('invalid status')));

        $this->assertFalse($method->invoke(
            $store,
            new WorkflowRuntimeException('Failed to load workflow run [run-2]: connection refused'),
        ));
        $this->assertFalse($method->invoke($store, new \RuntimeException('unexpected')));
    }
}

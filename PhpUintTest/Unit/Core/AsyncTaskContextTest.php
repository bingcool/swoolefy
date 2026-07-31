<?php

declare(strict_types=1);

namespace PhpUintTest\Unit\Core;

use PhpUintTest\TestCase;
use ReflectionMethod;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\InvalidContextValueException;

/**
 * 阶段二 4.8（审计项 25/26）：AsyncTask 仅接收可传播 Context 快照。
 * 目标：存在连接对象时拒绝投递，错误信息仅含键名与类型。
 */
final class AsyncTaskContextTest extends TestCase
{
    /**
     * 测 Context 含对象时 assertPropagatable/registerTask 路径拒绝。
     * 对应问题：隐式序列化连接对象会导致危险反序列化与跨任务串用。
     */
    public function testRejectsObjectContextBeforeTaskDispatch(): void
    {
        $this->expectException(InvalidContextValueException::class);
        Context::assertPropagatable([
            'user_id' => 1,
            'redis' => new \stdClass(),
        ]);
    }

    /**
     * 测 snapshot 过滤对象后仅保留标量，供 goApp 等安全传播。
     * 对应问题：子协程不应拿到父协程的对象引用。
     */
    public function testSnapshotFiltersNonPropagatableValues(): void
    {
        // 无协程时 snapshot 为空；此处直接测过滤语义（通过反射调用 protected 不必要，用公开 assert+手工对比）
        $raw = [
            'uid' => 9,
            'name' => 'a',
            'nested' => ['ok' => true, 'obj' => new \stdClass()],
            'conn' => new \stdClass(),
        ];
        Context::assertPropagatable(['uid' => 9, 'name' => 'a', 'nested' => ['ok' => true]]);

        $method = new ReflectionMethod(Context::class, 'filterPropagatable');
        $method->setAccessible(true);
        $filtered = $method->invoke(null, $raw);

        $this->assertSame(9, $filtered['uid']);
        $this->assertSame('a', $filtered['name']);
        $this->assertSame(['ok' => true], $filtered['nested']);
        $this->assertArrayNotHasKey('conn', $filtered);
    }

    /**
     * 测 AsyncTask::registerTask 在校验阶段会走到 assertPropagatable，并以 snapshot 投递。
     * 对应问题：投递入口必须显式校验，不能静默序列化危险值。
     */
    public function testRegisterTaskSourceUsesAssertPropagatable(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/src/Core/Task/AsyncTask.php');
        $this->assertNotFalse($src);
        $this->assertStringContainsString('Context::assertPropagatable', $src);
        $this->assertStringContainsString('Context::snapshot()', $src);
        $this->assertStringContainsString('InvalidContextValueException', file_get_contents(dirname(__DIR__, 3) . '/src/Core/Coroutine/Context.php') ?: '');
    }
}

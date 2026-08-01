<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Core;

use PHPUintTest\TestCase;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Exception\ContextUnavailableException;
use Swoolefy\Exception\InvalidContextValueException;

/**
 * 阶段二 4.4（审计项 5）：Context 空环境边界与可传播快照。
 * 目标：无协程/无 App 时 snapshot 安全、set 抛可诊断异常、不回退进程级共享区。
 */
final class ContextBoundaryTest extends TestCase
{
    /**
     * 测普通 CLI（无协程、无 Application）调用 snapshot 返回空数组且不 TypeError。
     * 对应问题：旧代码 getContext()->getArrayCopy() 在 null 上致命。
     */
    public function testSnapshotReturnsEmptyOutsideCoroutineAndApp(): void
    {
        $this->assertSame([], Context::snapshot());
        $this->assertFalse(Context::has('any'));
        $this->assertNull(Context::get('any'));
        $this->assertSame('fallback', Context::get('any', 'fallback'));
        $this->assertTrue(Context::delete('missing'));
    }

    /**
     * 测无上下文时 set 抛出 ContextUnavailableException。
     * 对应问题：写入进程级静态共享区会造成请求串数据。
     */
    public function testSetThrowsWhenContextUnavailable(): void
    {
        $this->expectException(ContextUnavailableException::class);
        Context::set('k', 'v');
    }

    /**
     * 测 assertPropagatable 拒绝对象并只暴露键名与类型。
     * 对应问题：AsyncTask 序列化连接对象不安全。
     */
    public function testAssertPropagatableRejectsObjectWithKeyAndTypeOnly(): void
    {
        try {
            Context::assertPropagatable(['conn' => new \stdClass()]);
            $this->fail('expected InvalidContextValueException');
        } catch (InvalidContextValueException $e) {
            $this->assertStringContainsString('conn', $e->getMessage());
            $this->assertStringContainsString('stdClass', $e->getMessage());
            $this->assertStringNotContainsString('password', $e->getMessage());
        }
    }
}

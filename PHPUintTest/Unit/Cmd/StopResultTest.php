<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\DTO\StopResult;
use Swoolefy\Cmd\DTO\StopStatus;

/**
 * StopResult 值对象单元测试。
 *
 * 验证：
 * - 各静态工厂方法创建正确的实例
 * - isSuccessful() 判断逻辑正确
 * - readonly 属性值正确
 */
class StopResultTest extends TestCase
{
    /**
     * 测试 success() 工厂方法。
     */
    public function testSuccess(): void
    {
        $result = StopResult::success(1234);

        $this->assertSame(StopStatus::SUCCESS, $result->status);
        $this->assertSame(1234, $result->pid);
        $this->assertStringContainsString('1234', $result->message);
        $this->assertTrue($result->isSuccessful());
    }

    /**
     * 测试 alreadyStopped() 工厂方法。
     */
    public function testAlreadyStopped(): void
    {
        $result = StopResult::alreadyStopped();

        $this->assertSame(StopStatus::ALREADY_STOPPED, $result->status);
        $this->assertSame(0, $result->pid);
        $this->assertTrue($result->isSuccessful());
    }

    /**
     * 测试 timeout() 工厂方法。
     */
    public function testTimeout(): void
    {
        $result = StopResult::timeout(5678);

        $this->assertSame(StopStatus::TIMEOUT, $result->status);
        $this->assertSame(5678, $result->pid);
        $this->assertFalse($result->isSuccessful());
    }

    /**
     * 测试 pidFileNotFound() 工厂方法。
     */
    public function testPidFileNotFound(): void
    {
        $result = StopResult::pidFileNotFound('/tmp/test.pid');

        $this->assertSame(StopStatus::PID_NOT_FOUND, $result->status);
        $this->assertSame(0, $result->pid);
        $this->assertStringContainsString('/tmp/test.pid', $result->message);
        $this->assertFalse($result->isSuccessful());
    }

    /**
     * 测试 invalidPid() 工厂方法。
     */
    public function testInvalidPid(): void
    {
        $result = StopResult::invalidPid('/tmp/test.pid');

        $this->assertSame(StopStatus::INVALID_PID, $result->status);
        $this->assertSame(0, $result->pid);
        $this->assertStringContainsString('/tmp/test.pid', $result->message);
        $this->assertFalse($result->isSuccessful());
    }

    /**
     * 测试 isSuccessful() 仅在 SUCCESS 和 ALREADY_STOPPED 时返回 true。
     */
    public function testIsSuccessful(): void
    {
        // 成功场景
        $this->assertTrue(StopResult::success(1)->isSuccessful());
        $this->assertTrue(StopResult::alreadyStopped()->isSuccessful());

        // 失败场景
        $this->assertFalse(StopResult::timeout(1)->isSuccessful());
        $this->assertFalse(StopResult::pidFileNotFound('')->isSuccessful());
        $this->assertFalse(StopResult::invalidPid('')->isSuccessful());
    }

    /**
     * 测试 StopResult 是不可变的（readonly 属性）。
     */
    public function testImmutability(): void
    {
        $result = StopResult::success(1234);

        // readonly 属性不可修改（PHP 会抛 Error）
        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line */
        $result->pid = 9999;
    }
}

<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Cmd;

use PHPUnit\Framework\TestCase;
use Swoolefy\Cmd\DTO\StopStatus;

/**
 * StopStatus enum 单元测试。
 *
 * 验证：
 * - 所有 case 的 value 值正确
 * - label() 返回中文标签
 * - backed enum 可通过 from()/tryFrom() 反查
 */
class StopStatusTest extends TestCase
{
    /**
     * 测试每个 enum case 的 backed value 值。
     */
    public function testEnumValues(): void
    {
        $this->assertSame('success', StopStatus::SUCCESS->value);
        $this->assertSame('already_stopped', StopStatus::ALREADY_STOPPED->value);
        $this->assertSame('timeout', StopStatus::TIMEOUT->value);
        $this->assertSame('pid_not_found', StopStatus::PID_NOT_FOUND->value);
        $this->assertSame('invalid_pid', StopStatus::INVALID_PID->value);
    }

    /**
     * 测试 label() 返回中文标签。
     */
    public function testLabels(): void
    {
        $this->assertSame('停止成功', StopStatus::SUCCESS->label());
        $this->assertSame('服务已停止', StopStatus::ALREADY_STOPPED->label());
        $this->assertSame('停止超时', StopStatus::TIMEOUT->label());
        $this->assertSame('PID文件不存在', StopStatus::PID_NOT_FOUND->label());
        $this->assertSame('PID无效', StopStatus::INVALID_PID->label());
    }

    /**
     * 测试 from() 可通过 value 反查 enum case。
     */
    public function testFromValue(): void
    {
        $this->assertSame(StopStatus::SUCCESS, StopStatus::from('success'));
        $this->assertSame(StopStatus::TIMEOUT, StopStatus::from('timeout'));
    }

    /**
     * 测试 tryFrom() 对无效值返回 null。
     */
    public function testTryFromInvalidValue(): void
    {
        $this->assertNull(StopStatus::tryFrom('nonexistent'));
    }

    /**
     * 测试所有 case 都有 label。
     */
    public function testAllCasesHaveLabels(): void
    {
        foreach (StopStatus::cases() as $case) {
            $label = $case->label();
            $this->assertNotEmpty($label, "Case {$case->name} should have a non-empty label");
        }
    }
}

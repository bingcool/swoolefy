<?php

declare(strict_types=1);

namespace Swoolefy\Cmd\DTO;

/**
 * 停止操作的不可变结果对象。
 *
 * 使用 StopStatus enum 替代字符串比较，类型安全且避免拼写错误。
 * 通过命名构造函数（静态工厂方法）创建实例，使语义清晰。
 *
 * 用法示例:
 *   $result = StopResult::success(1234);
 *   if ($result->isSuccessful()) { ... }
 *   fmtPrintInfo($result->status->label());
 */
final class StopResult
{
    /**
     * @param StopStatus $status  停止操作的状态枚举
     * @param int        $pid     相关进程 PID（停止成功时为被停止的 PID，其他情况可能为 0）
     * @param string     $message 人类可读的结果描述
     */
    public function __construct(
        public readonly StopStatus $status,
        public readonly int $pid = 0,
        public readonly string $message = '',
    ) {}

    /**
     * 创建"停止成功"结果。
     */
    public static function success(int $pid): self
    {
        return new self(StopStatus::SUCCESS, $pid, "Server (pid={$pid}) stopped successfully");
    }

    /**
     * 创建"服务已停止"结果（幂等停止场景）。
     */
    public static function alreadyStopped(): self
    {
        return new self(StopStatus::ALREADY_STOPPED, 0, 'Server had already stopped');
    }

    /**
     * 创建"停止超时"结果（优雅停机超时后强制杀死）。
     */
    public static function timeout(int $pid): self
    {
        return new self(StopStatus::TIMEOUT, $pid, "Stop timeout, force killed remaining processes (pid={$pid})");
    }

    /**
     * 创建"PID文件不存在"结果。
     */
    public static function pidFileNotFound(string $pidFile): self
    {
        return new self(StopStatus::PID_NOT_FOUND, 0, "PID file not found: {$pidFile}");
    }

    /**
     * 创建"PID无效"结果。
     */
    public static function invalidPid(string $pidFile): self
    {
        return new self(StopStatus::INVALID_PID, 0, "Invalid PID in file: {$pidFile}");
    }

    /**
     * 判断停止操作是否成功（包括"已成功停止"和"本就处于停止状态"两种情况）。
     */
    public function isSuccessful(): bool
    {
        return $this->status === StopStatus::SUCCESS || $this->status === StopStatus::ALREADY_STOPPED;
    }
}

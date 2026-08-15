<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Worker\Cron;

/**
 * 单次 Execution 的不可变结果。
 *
 * SKIPPED 由 CronManager 管线（时间窗 / 重叠）产生，Executor 只返回 SUCCESS / FAILED。
 * pid 是子进程 PID（Shell），HTTP 保持 0。httpStatus / exitCode 供日志与单测断言。
 *
 * 本对象不驱动调度：无论哪种 status，下一轮 Timer 都已在 onTrigger 开头武装。
 *
 * @see CronExecutorInterface
 * @see CronMetrics::recordRun()
 */
final class ExecutionResult
{
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';
    public const SKIPPED = 'SKIPPED';

    public function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly int $pid = 0,
        public readonly ?int $exitCode = null,
        public readonly ?int $httpStatus = null,
    ) {
    }

    /**
     * Shell 默认 exitCode=0；HTTP 可带 httpStatus。
     */
    public static function success(string $message = 'ok', int $pid = 0, ?int $exitCode = 0, ?int $httpStatus = null): self
    {
        return new self(self::SUCCESS, $message, $pid, $exitCode, $httpStatus);
    }

    /**
     * 执行失败或异常隔离后的结果。message 会写入 cron_task_log。
     */
    public static function failed(string $message, int $pid = 0, ?int $exitCode = null, ?int $httpStatus = null): self
    {
        return new self(self::FAILED, $message, $pid, $exitCode, $httpStatus);
    }

    /**
     * 时间窗 / 重叠 SKIP。工厂保留给测试与指标；生产路径多直接 recordSkip。
     */
    public static function skipped(string $message): self
    {
        return new self(self::SKIPPED, $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }
}

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
 * SKIPPED 由 CronManager 管线（时间窗 / 重叠）产生；Executor 返回 SUCCESS / FAILED / TIMEOUT。
 * pid 是子进程 PID（Shell），HTTP 保持 0。httpStatus / exitCode 供日志与单测断言。
 * 落库时由 {@see ExecutionStatus::fromResult()} 映射为 cron_task_log.status 整型。
 *
 * 本对象不驱动调度：onTrigger 无论哪种 status，下一轮 Timer 都已在开头武装。
 * runOnceNow 不改 Timer / nextRunAt。
 *
 * @see CronExecutorInterface
 * @see CronMetrics::recordRun()
 */
final class ExecutionResult
{
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';
    public const SKIPPED = 'SKIPPED';
    public const TIMEOUT = 'TIMEOUT';
    public const CANCELLED = 'CANCELLED';

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

    /**
     * 执行超时（HTTP cURL 28 / 读超时等）。不参与 retry 循环外的额外语义，与 FAILED 一样算一次 Execution。
     */
    public static function timeout(string $message, int $pid = 0, ?int $exitCode = null, ?int $httpStatus = null): self
    {
        return new self(self::TIMEOUT, $message, $pid, $exitCode, $httpStatus);
    }

    /**
     * 执行被取消（Worker 停机等）。当前管线较少写入；数据模型预留。
     */
    public static function cancelled(string $message, int $pid = 0): self
    {
        return new self(self::CANCELLED, $message, $pid);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::SKIPPED;
    }

    /**
     * 本轮（或某次 attempt）执行失败。CronManager 只对 FAILED 做 retry。
     */
    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    public function isTimeout(): bool
    {
        return $this->status === self::TIMEOUT;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * 本轮手动执行请求是否应 ack。
     *
     * SUCCESS / FAILED / TIMEOUT / CANCELLED 表示请求已被消费。
     * SKIPPED（时间窗 / 重叠）未真正执行该请求，保留队列由下一轮 Polling 再试。
     */
    public function isCompleted(): bool
    {
        return $this->isSuccess() || $this->isFailed() || $this->isTimeout() || $this->isCancelled();
    }
}

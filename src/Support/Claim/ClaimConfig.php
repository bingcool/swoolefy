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

declare(strict_types=1);

namespace Swoolefy\Support\Claim;

/**
 * 一个队列的参数：用哪张表、哪个 job，以及认领与重试策略。
 *
 * 时间字段统一用「秒」而不是 time.Duration：建表语句里的租约和退避列都是
 * DATETIME（秒精度），读写两侧用同一个单位不会在向上取整时产生歧义。
 * 对应 gofy claim.Config。
 */
final class ClaimConfig
{
    /**
     * 默认批大小故意取得小。单实例是串行处理一批的，大批次不会提高吞吐，只会拉长排队尾巴，
     * 并让一个 worker 同时租住更多行。单实例要更并行应该加并发，而不是加批大小。
     */
    public const DEFAULT_BATCH = 10;

    /**
     * 认领批大小的硬上限，ClaimQueue 构造与 claim() 都会拒绝超过它的值。
     * Runner 在每行 handler 开始前会重新武装租约，所以超大批次并不会算错——只是共享截止
     * 时间到期时，还在排队的行每人白等一个回收周期。
     */
    public const MAX_BATCH = 20;

    public const DEFAULT_LEASE_SECONDS = 60;

    public const DEFAULT_MAX_ATTEMPTS = 5;

    public const DEFAULT_BACKOFF_SECONDS = 10;

    public const DEFAULT_MAX_BACKOFF_SECONDS = 600;

    /**
     * @param string $table 任务表，可带库名前缀，例如 app.job_task
     * @param string $job 任务类型，写入/查询 job_type 列
     * @param int $batch 默认认领批大小，<= 0 用 DEFAULT_BATCH，> MAX_BATCH 会被拒绝
     * @param int $leaseSeconds 认领租约秒数，<= 0 用 DEFAULT_LEASE_SECONDS。Runner 会在每个
     *        handler 开始前重新武装它，因此只需大于单次 handler 的最长合法耗时（p99 加余量），
     *        批大小不再放大这个要求。重新武装只保护排队窗口：跑过租约的 handler 仍可能在执行
     *        途中被接管并再跑一遍，所以 handler 必须幂等。Runner 会拒绝
     *        handlerTimeoutSeconds >= leaseSeconds 的配置
     * @param int $maxAttempts 最多认领次数（含成功那次），<= 0 用 DEFAULT_MAX_ATTEMPTS
     * @param int $backoffSeconds 首次失败重试延迟，<= 0 用 DEFAULT_BACKOFF_SECONDS
     * @param int $maxBackoffSeconds 退避上限，<= 0 用 DEFAULT_MAX_BACKOFF_SECONDS
     * @param ClaimHooks|null $hooks 观测钩子
     */
    public function __construct(
        public readonly string $table = '',
        public readonly string $job = '',
        public readonly int $batch = 0,
        public readonly int $leaseSeconds = 0,
        public readonly int $maxAttempts = 0,
        public readonly int $backoffSeconds = 0,
        public readonly int $maxBackoffSeconds = 0,
        public readonly ?ClaimHooks $hooks = null,
    ) {
    }

    /**
     * 返回补齐默认值、并 trim 过表名/job 的新配置；自身不变。
     */
    public function withDefaults(): self
    {
        $batch = $this->batch > 0 ? $this->batch : self::DEFAULT_BATCH;
        $lease = $this->leaseSeconds > 0 ? $this->leaseSeconds : self::DEFAULT_LEASE_SECONDS;
        $maxAttempts = $this->maxAttempts > 0 ? $this->maxAttempts : self::DEFAULT_MAX_ATTEMPTS;
        $backoff = $this->backoffSeconds > 0 ? $this->backoffSeconds : self::DEFAULT_BACKOFF_SECONDS;
        $maxBackoff = $this->maxBackoffSeconds > 0 ? $this->maxBackoffSeconds : self::DEFAULT_MAX_BACKOFF_SECONDS;
        if ($maxBackoff < $backoff) {
            $maxBackoff = $backoff;
        }

        return new self(
            table: trim($this->table),
            job: trim($this->job),
            batch: $batch,
            leaseSeconds: $lease,
            maxAttempts: $maxAttempts,
            backoffSeconds: $backoff,
            maxBackoffSeconds: $maxBackoff,
            hooks: $this->hooks,
        );
    }

    /**
     * 已尝试 n 次的行，下一次重试前的延迟秒数（指数退避，封顶）。
     */
    public static function backoffSeconds(int $base, int $max, int $attempts): int
    {
        if ($attempts < 1) {
            $attempts = 1;
        }
        $delay = $base;
        for ($i = 1; $i < $attempts; ++$i) {
            if ($delay >= $max) {
                break;
            }
            $delay *= 2;
        }

        return min($delay, $max);
    }
}

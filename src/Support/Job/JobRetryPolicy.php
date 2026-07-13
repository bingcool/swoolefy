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

namespace Swoolefy\Support\Job;

/**
 * JobRunner 的重试 / 退避策略。
 *
 * attempt 从 1 起计（首次投递 = 1）。shouldRetry(attempt) 表示：
 * 「本次失败后，是否还可调度 attempt+1」。
 */
final class JobRetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public int $baseDelayMs = 1000,
        public float $backoffMultiplier = 2.0,
        public int $maxDelayMs = 300_000,
        public float $jitterRatio = 0.2,
    ) {
        // 参数下限钳制，避免非法配置导致负延迟或零次尝试
        if ($this->maxAttempts < 1) {
            $this->maxAttempts = 1;
        }
        if ($this->baseDelayMs < 0) {
            $this->baseDelayMs = 0;
        }
        if ($this->backoffMultiplier < 1.0) {
            $this->backoffMultiplier = 1.0;
        }
        if ($this->maxDelayMs < 0) {
            $this->maxDelayMs = 0;
        }
        if ($this->jitterRatio < 0.0) {
            $this->jitterRatio = 0.0;
        }
    }

    /** 给定失败 attempt 后，是否还可再调度一次。 */
    public function shouldRetry(int $attempt): bool
    {
        return $attempt < $this->maxAttempts;
    }

    /** 当前失败后，到下一次 attempt 的延迟（毫秒）。 */
    public function delayMsForAttempt(int $attempt): int
    {
        // 指数退避：base * multiplier^(attempt-1)，再封顶 maxDelayMs
        $exp = max(0, $attempt - 1);
        $raw = (int) min(
            $this->maxDelayMs,
            (int) round($this->baseDelayMs * ($this->backoffMultiplier ** $exp)),
        );
        if ($this->jitterRatio <= 0.0 || $raw <= 0) {
            return max(0, $raw);
        }
        // 叠加随机抖动，避免惊群同时重试
        $jitter = (int) ($raw * $this->jitterRatio * (mt_rand(0, 1000) / 1000));

        return max(0, $raw + $jitter);
    }
}

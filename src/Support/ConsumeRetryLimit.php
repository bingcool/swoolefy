<?php

declare(strict_types=1);

namespace Swoolefy\Support;

/**
 * 消费失败重试预算（Kafka / AMQP handler 共用）。
 *
 * 同一条消息连续失败时累计；处理成功、换下一条或丢弃后重置。
 * `$maxRetryAttempts` 是同一条消息最多交给 onMessage() 的次数，0 或负数表示不限制。
 * 两次重试之间指数退避，给下游瞬时抖动（数据库重启、依赖发布）留出恢复窗口：
 * 丢弃是不可逆动作，宁可多等，也不要在一两秒内就把消息判成毒消息。
 */
trait ConsumeRetryLimit
{
    /**
     * 同一条消息的最大处理次数，业务 handler 可重写。
     * 默认 5 次配合下面的退避约等于 15 秒容忍窗口（1+2+4+8）；
     * 故障期更长就加大该值，或设为 0 恢复无限重试。
     */
    protected int $maxRetryAttempts = 5;

    /** 第一次失败后的重试等待秒数，之后每次翻倍。 */
    protected float $retryBackoffBaseSeconds = 1.0;

    /** 单次重试等待上限（秒），防止长尾故障把停机等待拖得过长。 */
    protected float $retryBackoffMaxSeconds = 15.0;

    private int $consumeFailedAttempts = 0;

    /**
     * 记录一次失败；返回 true 表示已达上限，调用方应停止重试并丢弃或转死信。
     */
    protected function markConsumeFailure(): bool
    {
        $this->consumeFailedAttempts++;

        return $this->maxRetryAttempts > 0 && $this->consumeFailedAttempts >= $this->maxRetryAttempts;
    }

    protected function resetConsumeFailures(): void
    {
        $this->consumeFailedAttempts = 0;
    }

    /**
     * 当前消息已失败的次数，用于丢弃日志和退避计算。
     */
    protected function consumeFailedAttempts(): int
    {
        return $this->consumeFailedAttempts;
    }

    /**
     * 本次失败后应等待的秒数：基数 × 2^(已失败次数-1)，封顶 $retryBackoffMaxSeconds。
     * 基数配成 0 时不等待，退化为紧密重试。
     */
    protected function consumeRetryDelaySeconds(): float
    {
        $base = max(0.0, $this->retryBackoffBaseSeconds);
        if ($base === 0.0) {
            return 0.0;
        }

        $exponent = min(max($this->consumeFailedAttempts - 1, 0), 30);

        return min($base * (2 ** $exponent), max($base, $this->retryBackoffMaxSeconds));
    }

    /**
     * 分片等待下一次重试，$shouldStop 为真立即返回。
     * 停机标志出现时不必等完整退避时长，分片粒度保证优雅退出不被长等待放大。
     *
     * @param callable(): bool $shouldStop
     */
    protected function waitConsumeRetry(callable $shouldStop): void
    {
        $deadline = microtime(true) + $this->consumeRetryDelaySeconds();
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || $shouldStop()) {
                return;
            }
            \Swoole\Coroutine::sleep(min(0.2, $remaining));
        }
    }
}

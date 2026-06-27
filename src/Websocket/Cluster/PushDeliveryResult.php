<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * 单条集群推送指令的投递结果（用于 Streams XACK 决策）。
 *
 * ## ACK 策略（shouldAck）
 *
 * | 场景 | ACK | 说明 |
 * |------|-----|------|
 * | 非法 JSON / 空 payload | 是 | 毒消息，避免 PEL 无限重试 |
 * | Server 不可用 | 否 | 消费进程未就绪，留 PEL 重试 |
 * | 至少 1 个 fd push 成功 | 是 | 部分成功视为完成 |
 * | 无目标 / 本节点无连接 | 是 | 本节点无需投递 |
 * | 全部 fd 已断开（gone） | 是 | 连接不存在，重试无意义 |
 * | 全部 enricher 跳过（skipped） | 是 | 业务明确不投递 |
 * | 存在 established 但 push 失败（failed） | 否 | 可能缓冲区满等临时故障，留 PEL |
 *
 * @see PushDeliveryWorker
 * @see PushStreamConsumer
 */
class PushDeliveryResult
{
    public int $delivered = 0;

    /** fd 未建立（连接已关闭） */
    public int $gone = 0;

    /** enricher 返回 null 等业务跳过 */
    public int $skipped = 0;

    /** fd 在线但 server->push 失败 */
    public int $failed = 0;

    public bool $invalidPayload = false;

    public bool $serverUnavailable = false;

    public static function invalidPayload(): self
    {
        $result = new self();
        $result->invalidPayload = true;

        return $result;
    }

    public static function serverUnavailable(): self
    {
        $result = new self();
        $result->serverUnavailable = true;

        return $result;
    }

    public function recordDelivered(): void
    {
        $this->delivered++;
    }

    public function recordGone(): void
    {
        $this->gone++;
    }

    public function recordSkipped(): void
    {
        $this->skipped++;
    }

    public function recordFailed(): void
    {
        $this->failed++;
    }

    /**
     * 记录单 fd 投递结果（delivered / gone / skipped / failed）。
     */
    public function recordOutcome(string $outcome): void
    {
        switch ($outcome) {
            case 'delivered':
                $this->recordDelivered();
                break;
            case 'gone':
                $this->recordGone();
                break;
            case 'skipped':
                $this->recordSkipped();
                break;
            case 'failed':
                $this->recordFailed();
                break;
        }
    }

    public function merge(self $other): void
    {
        $this->delivered += $other->delivered;
        $this->gone += $other->gone;
        $this->skipped += $other->skipped;
        $this->failed += $other->failed;
        $this->invalidPayload = $this->invalidPayload || $other->invalidPayload;
        $this->serverUnavailable = $this->serverUnavailable || $other->serverUnavailable;
    }

    /**
     * 是否应对 Stream entry 执行 XACK。
     */
    public function shouldAck(): bool
    {
        if ($this->invalidPayload) {
            return true;
        }

        if ($this->serverUnavailable) {
            return false;
        }

        if ($this->delivered > 0) {
            return true;
        }

        $attempted = $this->gone + $this->skipped + $this->failed;
        if ($attempted === 0) {
            return true;
        }

        if ($this->failed === 0) {
            return true;
        }

        return false;
    }

    /** 兼容 ClusterPushBus 等仍需要成功数的调用方 */
    public function deliveredCount(): int
    {
        return $this->delivered;
    }
}

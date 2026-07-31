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
 * | Server 不可用 | 否（停机中例外） | 消费进程未就绪，留 PEL 重试 |
 * | 全部目标 delivered / gone / skipped | 是 | 无可重试失败 |
 * | 无目标 / 本节点无连接 | 是 | 本节点无需投递 |
 * | 任一目标 failed（可重试） | 否 | 即使部分已 delivered 也不得整条 ACK |
 * | 去重命中（duplicateSkipped） | 是 | XAUTOCLAIM 重投同 msg_id，跳过重复 push |
 *
 * 当前版本不按 target 拆分 Stream；PEL 重试依赖 {@see PushDedupStore} 的 msg_id 幂等，
 * 避免已成功目标在完整 ACK 前被重复计入“已处理”。
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

    /** fd 在线但 server->push 失败（可重试） */
    public int $failed = 0;

    public bool $invalidPayload = false;

    public bool $serverUnavailable = false;

    /** Redis 去重命中：同 msg_id 已投递过，跳过重复 push */
    public bool $duplicateSkipped = false;

    /**
     * 逐 target 投递明细（group / broadcast 离线回补按 user_id 聚合）。
     *
     * @var array<int, array{fd:int,conn_id:string,user_id:string,outcome:string}>
     */
    public array $targetDetails = [];

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

    public static function duplicateSkipped(): self
    {
        $result = new self();
        $result->duplicateSkipped = true;

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

    /**
     * 记录单 fd 投递结果，并写入 targetDetails 供离线按 user_id 聚合。
     *
     * group/broadcast 一次指令含多个 fd，OfflineMessageCoordinator 依赖此明细
     * 判断「某用户全部 gone 且无 delivered」时才落库。
     */
    public function recordTargetOutcome(int $fd, string $connId, string $userId, string $outcome): void
    {
        $this->recordOutcome($outcome);
        $this->targetDetails[] = [
            'fd' => $fd,
            'conn_id' => $connId,
            'user_id' => trim($userId),
            'outcome' => $outcome,
        ];
    }

    /** 可重试失败数（当前即 failed；serverUnavailable 由 shouldAck 单独处理） */
    public function retryableCount(): int
    {
        return $this->failed;
    }

    public function merge(self $other): void
    {
        $this->delivered += $other->delivered;
        $this->gone += $other->gone;
        $this->skipped += $other->skipped;
        $this->failed += $other->failed;
        $this->invalidPayload = $this->invalidPayload || $other->invalidPayload;
        $this->serverUnavailable = $this->serverUnavailable || $other->serverUnavailable;
        $this->duplicateSkipped = $this->duplicateSkipped || $other->duplicateSkipped;
    }

    /**
     * 是否应对 Stream entry 执行 XACK。
     *
     * 仅当全部目标已 delivered 或明确不可重试（gone/skipped/毒消息）时 ACK。
     * 任一目标 failed（可重试）时不得 ACK，即使部分已成功——PEL 重试 + msg_id 幂等。
     *
     * 优雅停机中 serverUnavailable：Worker/Server 正在退出，PEL 重试无意义，
     * 应 ACK 以放行 Master waitForStreamPelDrain（否则卡满 drain_timeout）。
     */
    public function shouldAck(): bool
    {
        if ($this->duplicateSkipped) {
            return true;
        }

        if ($this->invalidPayload) {
            return true;
        }

        if ($this->serverUnavailable) {
            return WebsocketShutdownCoordinator::shouldStopConsuming();
        }

        // 存在可重试失败时不得整条 ACK（部分成功也不例外）
        if ($this->failed > 0) {
            return false;
        }

        return true;
    }

    /** 兼容 ClusterPushBus 等仍需要成功数的调用方 */
    public function deliveredCount(): int
    {
        return $this->delivered;
    }
}

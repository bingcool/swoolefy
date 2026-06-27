<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * pushToUser / fanout 扇出结果（用于离线落库决策与 API 返回值）。
 *
 * - delivered：本节点实际 push 成功数
 * - remoteTargetCount：已写入远端 Stream/PubSub 的 target 数（非送达确认）
 * - targetCount：Redis 索引命中的连接总数
 */
class PushFanoutResult
{
    public int $delivered = 0;

    public int $remoteTargetCount = 0;

    public int $targetCount = 0;

    /** 推送时是否应写入离线表（索引中无任何可路由连接） */
    public function shouldStoreOfflineAtPush(): bool
    {
        return $this->targetCount === 0;
    }

    /**
     * 对外 API 返回值：本地已送达 > 0 取本地；否则取已排队远端 target 数；均无则 0。
     *
     * 不再把远端 target 数当作 deliveredCount，避免误判「已在线送达」。
     */
    public function reportedHitCount(): int
    {
        if ($this->delivered > 0) {
            return $this->delivered;
        }

        if ($this->remoteTargetCount > 0) {
            return $this->remoteTargetCount;
        }

        return 0;
    }

    public function deliveredCount(): int
    {
        return $this->delivered;
    }
}

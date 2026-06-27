<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * pushToUser / fanout 扇出结果（用于离线落库决策与 API 返回值）。
 *
 * ## 字段语义
 *
 * - delivered：本节点 `server->push()` 成功数（真实送达）
 * - remoteTargetCount：已写入远端 Stream 的 target 数（**排队**，非送达确认）
 * - targetCount：Redis 索引命中的连接总数（group/user）或在线节点数（broadcast）
 *
 * ## 离线落库
 *
 * `shouldStoreOfflineAtPush()` 仅看 targetCount：为 0 表示索引中无可路由目标，
 * 此时读 `data.offline_user_ids` 落库；>0 则等投递阶段按 user 聚合 gone 再落库。
 *
 * ## API 返回值
 *
 * `reportedHitCount()` 不再把 remoteTargetCount 当作 delivered，避免误判「用户已在线收到」。
 */
class PushFanoutResult
{
    public int $delivered = 0;

    public int $remoteTargetCount = 0;

    public int $targetCount = 0;

    /** user | group | targets | broadcast */
    public string $fanoutScope = 'targets';

    public ?string $fanoutGroup = null;

    /** @var string[] 扇出时索引命中的 user_id（去重） */
    public array $targetUserIds = [];

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

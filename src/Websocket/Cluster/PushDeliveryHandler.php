<?php

namespace Swoolefy\Websocket\Cluster;

use Swoole\WebSocket\Server;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Offline\OfflineMessageCoordinator;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 本节点最终投递层：将集群推送指令转为 `server->push()`。
 *
 * 投递前经 {@see PushDedupStore} 按 msg_id 去重（XAUTOCLAIM 重投场景）。
 * 投递后调用 {@see OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery()}：
 * 僵尸索引（Redis 有 conn、本地 fd 已 gone）按 user_id 聚合后落离线表。
 *
 * @see PushDeliveryResult  Streams XACK 决策
 * @see PushDeliveryWorker
 */
class PushDeliveryHandler
{
    /**
     * 处理一条集群推送指令（PushMessage 解码后的数组）。
     *
     * @return PushDeliveryResult 含 delivered 计数与 shouldAck() 决策
     */
    public static function deliver(Server $server, array $message): PushDeliveryResult
    {
        $msgId = PushDedupStore::extractMsgId($message);
        if ($msgId !== '' && PushDedupStore::isDuplicate($msgId)) {
            $result = PushDeliveryResult::duplicateSkipped();
            \Swoolefy\Websocket\Metrics\WebsocketMetrics::recordPushDedupSkipped();

            return $result;
        }

        $action = (string) ($message['action'] ?? PushMessage::ACTION_PUSH_EVENT);
        $event = (string) ($message['event'] ?? '');
        $data = $message['data'] ?? [];

        if ($action === PushMessage::ACTION_BROADCAST) {
            $result = self::deliverBroadcast($server, $event, $data);
        } else {
            $result = new PushDeliveryResult();
            $targets = is_array($message['targets'] ?? null) ? $message['targets'] : [];
            foreach ($targets as $target) {
                if (!is_array($target)) {
                    continue;
                }
                $fd = (int) ($target['fd'] ?? 0);
                if ($fd <= 0) {
                    continue;
                }
                $connId = (string) ($target['conn_id'] ?? '');
                // 优先本地 Table（最新 bindUser），回退 Redis meta（跨节点 Stream 投递）
                $userId = self::resolveUserIdForTarget($fd, $connId);
                $result->recordTargetOutcome(
                    $fd,
                    $connId,
                    $userId,
                    WebsocketConnectionManager::deliverEventToFdLocallyDetailed($server, $fd, $event, $data)
                );
            }
        }

        if ($msgId !== '' && $result->shouldAck()) {
            PushDedupStore::markProcessed($msgId);
        }

        // 与推送阶段互补：targetCount>0 但 fd gone 的用户在此落库
        OfflineMessageCoordinator::maybeStoreOfflineAfterDelivery($message, $result);

        \Swoolefy\Websocket\Metrics\WebsocketMetrics::recordPushDelivery($result);

        return $result;
    }

    private static function deliverBroadcast(Server $server, string $event, $data): PushDeliveryResult
    {
        $result = new PushDeliveryResult();
        if (!TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
            return $result;
        }

        // broadcast 遍历本节点全部 fd；user_id 来自 Table 行，供离线按用户聚合
        foreach (TableManager::getTable(WebsocketConnectionManager::TABLE_CONNECTIONS) as $row) {
            $fd = (int) ($row['fd'] ?? 0);
            if ($fd <= 0) {
                continue;
            }

            $connId = WebsocketConnectionManager::getConnIdByFd($fd);
            $userId = trim((string) ($row['user_id'] ?? ''));
            $result->recordTargetOutcome(
                $fd,
                $connId,
                $userId,
                WebsocketConnectionManager::deliverEventToFdLocallyDetailed($server, $fd, $event, $data)
            );
        }

        return $result;
    }

    private static function resolveUserIdForTarget(int $fd, string $connId): string
    {
        $connection = WebsocketConnectionManager::getConnection($fd);
        if (is_array($connection)) {
            $userId = trim((string) ($connection['user_id'] ?? ''));
            if ($userId !== '') {
                return $userId;
            }
        }

        if ($connId !== '') {
            $meta = RedisConnectionRegistry::getConnectionMeta($connId);
            if (is_array($meta)) {
                return trim((string) ($meta['user_id'] ?? ''));
            }
        }

        return '';
    }
}

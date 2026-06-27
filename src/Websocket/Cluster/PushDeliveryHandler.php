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
                self::recordOutcome($result, WebsocketConnectionManager::deliverEventToFdLocallyDetailed(
                    $server,
                    $fd,
                    $event,
                    $data
                ));
            }
        }

        if ($msgId !== '' && $result->shouldAck()) {
            PushDedupStore::markProcessed($msgId);
        }

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

        foreach (TableManager::getTable(WebsocketConnectionManager::TABLE_CONNECTIONS) as $row) {
            $fd = (int) ($row['fd'] ?? 0);
            if ($fd <= 0) {
                continue;
            }
            self::recordOutcome($result, WebsocketConnectionManager::deliverEventToFdLocallyDetailed(
                $server,
                $fd,
                $event,
                $data
            ));
        }

        return $result;
    }

    /** @internal 供 PushDeliveryResult::recordOutcome 复用 */
    private static function recordOutcome(PushDeliveryResult $result, string $outcome): void
    {
        $result->recordOutcome($outcome);
    }
}

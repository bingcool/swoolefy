<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Process\ProcessManager;

/**
 * 注册 WebSocket 集群推送自定义进程（EventCtrl::init 调用）。
 *
 * ## transport=streams（默认，生产推荐）
 *
 * ```
 * delivery_process_num = N
 *   → 注册 N 个 WebsocketPushStreamConsumerProcess
 *   → 共享 Stream + 消费组，竞争 XREADGROUP
 *   → 无需单独 SUBSCRIBE 进程（Streams 与 Pub/Sub 不同，可多 consumer 安全并行）
 * ```
 *
 * ## transport=pubsub（兼容旧版）
 *
 * ```
 * 1 × WebsocketPushSubscriberProcess（SUBSCRIBE，不可多开）
 * delivery_process_num > 1 时另注册 N 个 WebsocketPushDeliveryProcess（List BRPOP）
 * ```
 */
class WebsocketPushProcessRegistrar
{
    public static function register(): void
    {
        if (!ClusterConfig::isEnabled()) {
            return;
        }

        $deliveryNum = ClusterConfig::pushDeliveryProcessNum();

        if (ClusterConfig::usesPushStreams()) {
            for ($i = 0; $i < $deliveryNum; $i++) {
                ProcessManager::getInstance()->addProcess(
                    'swoolefy_websocket_push_stream_' . $i,
                    WebsocketPushStreamConsumerProcess::class,
                    true,
                    [],
                    ['consumer_index' => $i]
                );
            }

            return;
        }

        ProcessManager::getInstance()->addProcess(
            'swoolefy_websocket_push_subscriber',
            WebsocketPushSubscriberProcess::class
        );

        if ($deliveryNum <= 1) {
            return;
        }

        for ($i = 0; $i < $deliveryNum; $i++) {
            ProcessManager::getInstance()->addProcess(
                'swoolefy_websocket_push_delivery_' . $i,
                WebsocketPushDeliveryProcess::class
            );
        }
    }
}

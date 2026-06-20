<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Process\ProcessManager;

/**
 * 注册 WebSocket 集群推送相关自定义进程。
 *
 * delivery_process_num=1：单进程 SUBSCRIBE + 同步投递（默认，零队列开销）
 * delivery_process_num>1：1 个 SUBSCRIBE 入队 + N 个投递进程 BRPOP 并行 server->push()
 */
class WebsocketPushProcessRegistrar
{
    public static function register(): void
    {
        if (!ClusterConfig::isEnabled()) {
            return;
        }

        $deliveryNum = ClusterConfig::pushDeliveryProcessNum();
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

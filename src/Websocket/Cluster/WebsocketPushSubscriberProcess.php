<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * 每节点 1 个专用进程，订阅本节点 Redis 频道。
 *
 * delivery_process_num=1：收到消息后在本进程同步投递。
 * delivery_process_num>1：收到消息后 RPUSH 到本节点队列，由 WebsocketPushDeliveryProcess 并行消费。
 *
 * 注意：不可启动多个进程 SUBSCRIBE 同一频道，否则每条消息会被重复投递。
 *
 * SUBSCRIBE 会阻塞协程，不能放在 worker 内执行。
 */
class WebsocketPushSubscriberProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled()) {
            return;
        }

        $channel = ClusterConfig::pushChannelForServer(ClusterNodeIdentity::getServerId());
        \Swoole\Coroutine::create(function () use ($channel) {
            while (true) {
                try {
                    // 断线后自动重连，保证推送总线可用
                    ClusterRedisClient::subscribe($channel, function (string $payload) {
                        $this->dispatchPayload($payload);
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }
            }
        });
    }

    private function dispatchPayload(string $payload): void
    {
        if (ClusterConfig::pushDeliveryProcessNum() > 1) {
            PushDeliveryQueue::enqueue($payload);

            return;
        }

        PushDeliveryWorker::deliverEncodedPayload($payload);
    }

    public function onHandleException(\Throwable $throwable, $context = [])
    {
        if (method_exists(BaseServer::class, 'catchException')) {
            BaseServer::catchException($throwable);

            return;
        }

        trigger_error($throwable->getMessage(), E_USER_WARNING);
    }
}

<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * Pub/Sub 订阅进程（仅 transport=pubsub 时注册）。
 *
 * ## 与 Streams 的区别
 *
 * - Pub/Sub：消息不持久化，本进程崩溃期间 PUBLISH 的消息**永久丢失**
 * - 不可多进程 SUBSCRIBE 同一频道（会重复投递）
 * - delivery_process_num>1 时本进程只 SUBSCRIBE + RPUSH 本地 List，由 DeliveryProcess BRPOP
 *
 * transport=streams（默认）时请使用 WebsocketPushStreamConsumerProcess。
 *
 * SUBSCRIBE 循环使用 goApp，RPUSH 本地队列 / 直推时 Redis 走 EventController 协程单例。
 */
class WebsocketPushSubscriberProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled() || ClusterConfig::usesPushStreams()) {
            return;
        }

        $channel = ClusterConfig::pushChannelForServer(ClusterNodeIdentity::getServerId());
        // SUBSCRIBE 回调内 enqueue/deliver 会 execute()，须 goApp 绑定 EventController
        goApp(function () use ($channel) {
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

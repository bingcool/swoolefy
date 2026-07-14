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
 * - delivery_process_num>1 时本进程只 SUBSCRIBE + LPUSH 本地 List，由 DeliveryProcess BRPOP
 *
 * transport=streams（默认）时请使用 WebsocketPushStreamConsumerProcess。
 *
 * ## 优雅停机
 *
 * - 回调内若 shutting_down：多投递进程模式仍入队（交给 DeliveryProcess drain）；
 *   直推模式跳过新投递，避免半截 push。
 * - SIGTERM → gracefulShutdownDrain：多进程时等待本地队列排空或超时。
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
                if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                    break;
                }

                try {
                    // 断线后自动重连，保证推送总线可用
                    ClusterRedisClient::subscribe($channel, function (string $payload) {
                        $this->dispatchPayload($payload);
                    });
                } catch (\Throwable $throwable) {
                    if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                        break;
                    }
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }
            }
        });
    }

    private function dispatchPayload(string $payload): void
    {
        // 多投递进程：停机中仍入队，由 DeliveryProcess::gracefulShutdownDrain 排空
        if (ClusterConfig::pushDeliveryProcessNum() > 1) {
            PushDeliveryQueue::enqueue($payload);

            return;
        }

        // 直推：停机后不再发起新 push，避免 Worker/fd 已退出时半截投递
        if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
            return;
        }

        PushDeliveryWorker::deliverEncodedPayload($payload);
    }

    /**
     * SIGTERM：标记停机，促使 DeliveryProcess 退出 BRPOP 并排空本地队列。
     * SUBSCRIBE 阻塞连接会在进程退出时关闭。
     */
    public function gracefulShutdownDrain(): void
    {
        if (!ClusterConfig::isEnabled() || ClusterConfig::usesPushStreams()) {
            return;
        }

        WebsocketShutdownCoordinator::markShuttingDown();
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

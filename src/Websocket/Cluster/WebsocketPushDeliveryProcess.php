<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * Pub/Sub 模式下的推送投递消费进程（BRPOP 本地 List）。
 *
 * ## 适用条件
 *
 * 仅当 **同时满足** 以下条件时由 WebsocketPushProcessRegistrar 注册：
 *
 * - `cluster.enable=true`
 * - `cluster.push.transport=pubsub`（非 streams）
 * - `cluster.push.delivery_process_num > 1`
 *
 * ## 与订阅进程的分工
 *
 * ```
 * WebsocketPushSubscriberProcess  SUBSCRIBE 频道 → RPUSH 本地队列（快速入队）
 * WebsocketPushDeliveryProcess    BRPOP 队列     → server->push()（并行投递）
 * ```
 *
 * streams 模式请使用 WebsocketPushStreamConsumerProcess，无需本进程。
 *
 * 消费协程通过 goApp 包装，保证 ClusterRedisClient::execute() 使用协程级 Redis 单例。
 *
 * @see WebsocketPushSubscriberProcess
 * @see PushDeliveryQueue
 */
class WebsocketPushDeliveryProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled()
            || ClusterConfig::usesPushStreams()
            || ClusterConfig::pushDeliveryProcessNum() <= 1) {
            return;
        }

        // 子协程须 goApp，BRPOP 循环内 deliver 会调用 ClusterRedisClient::execute()
        goApp(function () {
            while (true) {
                try {
                    ClusterRedisClient::runDedicated(static function (ClusterRedisAdapterInterface $redis) {
                        while (!WebsocketShutdownCoordinator::shouldStopConsuming()) {
                            $payload = PushDeliveryQueue::dequeueBlocking($redis, 5);
                            if ($payload === null) {
                                continue;
                            }
                            PushDeliveryWorker::deliverEncodedPayload($payload);
                        }
                    });
                } catch (\Throwable $throwable) {
                    if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                        break;
                    }
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }

                if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                    break;
                }
            }
        });
    }

    /**
     * SIGTERM 时排空本地 BRPOP 队列。
     */
    public function gracefulShutdownDrain(): void
    {
        if (!ClusterConfig::isEnabled()
            || ClusterConfig::usesPushStreams()
            || ClusterConfig::pushDeliveryProcessNum() <= 1) {
            return;
        }

        WebsocketShutdownCoordinator::markShuttingDown();
        $deadline = time() + WebsocketShutdownCoordinator::drainTimeout();

        (new EventApp())->registerApp(function () use ($deadline) {
            ClusterRedisClient::runDedicated(static function (ClusterRedisAdapterInterface $redis) use ($deadline) {
                while (time() < $deadline) {
                    $payload = PushDeliveryQueue::dequeueBlocking($redis, 1);
                    if ($payload === null) {
                        break;
                    }
                    PushDeliveryWorker::deliverEncodedPayload($payload);
                }
            });
        });
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

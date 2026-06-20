<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
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

        \Swoole\Coroutine::create(function () {
            while (true) {
                try {
                    ClusterRedisClient::runDedicated(static function (ClusterRedisAdapterInterface $redis) {
                        while (true) {
                            $payload = PushDeliveryQueue::dequeueBlocking($redis, 5);
                            if ($payload === null) {
                                continue;
                            }
                            PushDeliveryWorker::deliverEncodedPayload($payload);
                        }
                    });
                } catch (\Throwable $throwable) {
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }
            }
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

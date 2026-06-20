<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * 推送投递消费进程：从本节点 Redis List 队列 BRPOP 并执行 server->push()。
 *
 * 与 WebsocketPushSubscriberProcess 配合：
 * - 订阅进程负责 SUBSCRIBE 频道并入队（不阻塞在投递上）
 * - 本进程可配置多个并行消费，缓解突发 push 堆积
 */
class WebsocketPushDeliveryProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled() || ClusterConfig::pushDeliveryProcessNum() <= 1) {
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

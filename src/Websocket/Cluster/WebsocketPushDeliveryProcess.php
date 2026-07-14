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
 * ## 优雅停机
 *
 * 1. 看到 shutting_down → 退出 BRPOP 循环
 * 2. SIGTERM gracefulShutdownDrain：排空队列剩余条目，并等待在途 deliver 结束
 *
 * 消费协程通过 goApp 包装，保证 ClusterRedisClient::execute() 使用协程级 Redis 单例。
 *
 * @see WebsocketPushSubscriberProcess
 * @see PushDeliveryQueue
 */
class WebsocketPushDeliveryProcess extends AbstractProcess
{
    /** 在途投递计数（协程 deliver 与 SIGTERM drain 协调） */
    private int $inFlight = 0;

    private bool $drainStarted = false;

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
                    ClusterRedisClient::runDedicated(function (ClusterRedisAdapterInterface $redis) {
                        while (!WebsocketShutdownCoordinator::shouldStopConsuming()) {
                            $payload = PushDeliveryQueue::dequeueBlocking($redis, 5);
                            if ($payload === null) {
                                continue;
                            }
                            $this->deliverTracked($payload);
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

            // 协程侧触发队列排水（与 SIGTERM 互斥）
            if (WebsocketShutdownCoordinator::isEnabled()) {
                $this->drainDeliveryQueue();
            }
        });
    }

    /**
     * SIGTERM 时排空本地 BRPOP 队列，并等待在途 push 结束。
     */
    public function gracefulShutdownDrain(): void
    {
        if (!ClusterConfig::isEnabled()
            || ClusterConfig::usesPushStreams()
            || ClusterConfig::pushDeliveryProcessNum() <= 1) {
            return;
        }

        WebsocketShutdownCoordinator::markShuttingDown();
        (new EventApp())->registerApp(function () {
            $this->drainDeliveryQueue();
        });
    }

    private function deliverTracked(string $payload): void
    {
        $this->inFlight++;
        try {
            PushDeliveryWorker::deliverEncodedPayload($payload);
        } finally {
            $this->inFlight = max(0, $this->inFlight - 1);
        }
    }

    private function drainDeliveryQueue(): void
    {
        if ($this->drainStarted) {
            return;
        }
        $this->drainStarted = true;

        $deadline = time() + WebsocketShutdownCoordinator::drainTimeout();
        ClusterRedisClient::runDedicated(function (ClusterRedisAdapterInterface $redis) use ($deadline) {
            while (time() < $deadline) {
                $payload = PushDeliveryQueue::dequeueBlocking($redis, 1);
                if ($payload === null) {
                    break;
                }
                $this->deliverTracked($payload);
            }
        });

        // 等待在途 deliver 结束（短轮询，避免 SIGTERM 路径提前 Event::exit）
        while ($this->inFlight > 0 && time() < $deadline) {
            usleep(50000);
        }
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

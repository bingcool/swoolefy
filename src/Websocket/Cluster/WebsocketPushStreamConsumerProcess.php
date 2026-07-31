<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
use Swoolefy\Core\EventApp;
use Swoolefy\Core\Process\AbstractProcess;

/**
 * Redis Streams 推送消费进程。
 *
 * ## 职责
 *
 * transport=streams（默认）时，由本进程替代 Pub/Sub 的 WebsocketPushSubscriberProcess：
 *
 * | 能力 | 说明 |
 * |------|------|
 * | 持久化 | 消息在 XADD 后写入 Redis，消费进程重启可继续处理 |
 * | 并行 | delivery_process_num 个进程同属一组，XREADGROUP 竞争消费 |
 * | 崩溃恢复 | 未 XACK 的消息由 PushStreamConsumer 内 XAUTOCLAIM 回收 |
 * | 优雅停机 | SIGTERM 后 drain PEL，避免正在处理的推送丢失 |
 *
 * ## 进程注册
 *
 * WebsocketPushProcessRegistrar 按 delivery_process_num 注册
 * swoolefy_websocket_push_stream_0 .. N-1，每进程 consumer 名唯一（含 pid）。
 *
 * ## ACK 策略
 *
 * 由 {@see PushDeliveryWorker::shouldAckStreamPayload()} 根据 {@see PushDeliveryResult} 决定：
 * - 至少一次 push 成功 → ACK
 * - 目标 fd 均已断开 / enricher 全部跳过 → ACK（重试无意义）
 * - server 不可用 / push 失败且 fd 仍在线 → 不 ACK，留 PEL 由 XAUTOCLAIM 重试
 *
 * 消费协程通过 goApp 包装；SIGTERM drain 通过 EventApp 同步包装。
 *
 * @see PushStreamConsumer
 * @see ClusterConfig::pushDeliveryProcessNum()
 */
class WebsocketPushStreamConsumerProcess extends AbstractProcess
{
    /** @var string|null 当前进程 consumer 名，供 SIGTERM drain 复用 */
    private ?string $consumerName = null;

    /** 防止协程 break 路径与 SIGTERM gracefulShutdownDrain 并发双重 drain */
    private bool $drainStarted = false;

    public function run()
    {
        if (!ClusterConfig::isEnabled() || ClusterConfig::pushTransport() !== 'streams') {
            return;
        }

        $extend = $this->getExtendData();
        $index = is_array($extend) ? (int) ($extend['consumer_index'] ?? 0) : 0;
        $this->consumerName = ClusterConfig::pushStreamConsumerName($index);
        $consumerName = $this->consumerName;
        $handler = static function (string $entryId, string $payload): bool {
            return PushDeliveryWorker::shouldAckStreamPayload($payload);
        };

        // 子协程须 goApp，否则 ClusterRedisClient::execute() 无 Application 上下文会 socket 冲突
        goApp(function () use ($consumerName, $handler) {
            while (true) {
                if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                    break;
                }

                try {
                    PushStreamConsumer::runControlled(
                        $consumerName,
                        $handler,
                        static fn (): bool => !WebsocketShutdownCoordinator::shouldStopConsuming()
                    );
                } catch (\Throwable $throwable) {
                    if (WebsocketShutdownCoordinator::shouldStopConsuming()) {
                        break;
                    }
                    $this->onHandleException($throwable);
                    \Swoole\Coroutine::sleep(1);
                }
            }

            // 看到停机标志后立刻 drain PEL（与 SIGTERM 路径互斥，只跑一次）
            if (WebsocketShutdownCoordinator::isEnabled()) {
                $this->drainStreamConsumer($handler);
            }
        });
    }

    /**
     * SIGTERM 时在 Event::exit 前同步 drain PEL（协程可能来不及跑完）。
     *
     * 与 run() 协程 break 路径共用 drainStarted，避免双重投递。
     */
    public function gracefulShutdownDrain(): void
    {
        if (!ClusterConfig::isEnabled() || !ClusterConfig::usesPushStreams() || $this->consumerName === null) {
            return;
        }

        WebsocketShutdownCoordinator::markShuttingDown();

        $handler = static function (string $entryId, string $payload): bool {
            return PushDeliveryWorker::shouldAckStreamPayload($payload);
        };

        // SIGTERM 非协程上下文：同步 registerApp，投递时 Redis 索引查询走协程单例
        EventApp::run(function () use ($handler) {
            $this->drainStreamConsumer($handler);
        });
    }

    /** @param callable(string, string): bool $handler */
    private function drainStreamConsumer(callable $handler): void
    {
        if ($this->consumerName === null || $this->drainStarted) {
            return;
        }
        $this->drainStarted = true;

        PushStreamConsumer::drain(
            $this->consumerName,
            $handler,
            time() + WebsocketShutdownCoordinator::drainTimeout()
        );
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

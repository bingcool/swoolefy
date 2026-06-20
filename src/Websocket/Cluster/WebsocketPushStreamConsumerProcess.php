<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\BaseServer;
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
 *
 * ## 进程注册
 *
 * WebsocketPushProcessRegistrar 按 delivery_process_num 注册
 * swoolefy_websocket_push_stream_0 .. N-1，每进程 consumer 名唯一（含 pid）。
 *
 * ## ACK 策略
 *
 * deliverEncodedPayload 无异常即返回 true → XACK。
 * 部分 fd push 失败仍 ACK（消息已尽力投递）；JSON 无法解析则在 Consumer 内 ACK 丢弃。
 *
 * @see PushStreamConsumer
 * @see ClusterConfig::pushDeliveryProcessNum()
 */
class WebsocketPushStreamConsumerProcess extends AbstractProcess
{
    public function run()
    {
        if (!ClusterConfig::isEnabled() || ClusterConfig::pushTransport() !== 'streams') {
            return;
        }

        $extend = $this->getExtendData();
        $index = is_array($extend) ? (int) ($extend['consumer_index'] ?? 0) : 0;
        $consumerName = ClusterConfig::pushStreamConsumerName($index);

        \Swoole\Coroutine::create(function () use ($consumerName) {
            while (true) {
                try {
                    PushStreamConsumer::run($consumerName, static function (string $entryId, string $payload): bool {
                        PushDeliveryWorker::deliverEncodedPayload($payload);

                        return true;
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

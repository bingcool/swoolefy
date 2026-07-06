<?php

namespace Swoolefy\Websocket\Cluster;

use Swoolefy\Core\Swfy;
use Swoolefy\Websocket\Metrics\WebsocketMetrics;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;

/**
 * 推送载荷 → 本节点 WebSocket 连接的统一投递入口。
 *
 * Streams 消费进程通过 {@see deliverWithResult()} 获取 ACK 决策；
 * Pub/Sub 路径仍使用 {@see deliverEncodedPayload()}（无需 XACK）。
 */
class PushDeliveryWorker
{
    /**
     * Pub/Sub / 本地队列投递（不涉及 Stream ACK）。
     */
    public static function deliverEncodedPayload(string $payload): void
    {
        self::deliverWithResult($payload);
    }

    /**
     * 解码并投递，返回细粒度结果供 Streams XACK 决策。
     */
    public static function deliverWithResult(string $payload): PushDeliveryResult
    {
        $message = PushMessage::decode($payload);
        if ($message === null) {
            return PushDeliveryResult::invalidPayload();
        }

        WebsocketTraceContext::apply(WebsocketTraceContext::extractFromMessage($message));
        self::observeStreamLag($message);

        $server = Swfy::getServer();
        if (!$server instanceof \Swoole\WebSocket\Server) {
            $result = PushDeliveryResult::serverUnavailable();
            WebsocketMetrics::recordPushDelivery($result);

            return $result;
        }

        return PushDeliveryHandler::deliver($server, $message);
    }

    /**
     * Streams 消费回调：true → XACK，false → 保留 PEL 等待重试。
     */
    public static function shouldAckStreamPayload(string $payload): bool
    {
        return self::deliverWithResult($payload)->shouldAck();
    }

    private static function observeStreamLag(array $message): void
    {
        $ts = (int) ($message['ts'] ?? 0);
        if ($ts <= 0) {
            return;
        }

        WebsocketMetrics::observeStreamLagMs(max(0, (time() - $ts) * 1000));
    }
}

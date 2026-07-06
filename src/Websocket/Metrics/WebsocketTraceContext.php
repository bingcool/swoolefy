<?php

namespace Swoolefy\Websocket\Metrics;

use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Library\CurlProxy\OpentelemetryMiddleware;
use Swoolefy\Util\Helper;

/**
 * WebSocket / 集群推送链路的 trace_id 传播。
 *
 * onMessage 已在 WebsocketServer 写入协程上下文；跨节点 push 通过 PushMessage.trace_id 传递，
 * 消费端在 PushDeliveryWorker 内恢复上下文，便于日志与排障串联。
 */
class WebsocketTraceContext
{
    public static function currentOrNew(): string
    {
        if (class_exists(SwooleContext::class)
            && SwooleContext::has(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID)) {
            $traceId = trim((string) SwooleContext::get(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID));

            return $traceId !== '' ? $traceId : Helper::UUid();
        }

        return Helper::UUid();
    }

    public static function apply(?string $traceId): void
    {
        $traceId = trim((string) $traceId);
        if ($traceId === '' || !class_exists(SwooleContext::class)) {
            return;
        }

        SwooleContext::set(OpentelemetryMiddleware::OPENTELEMETRY_X_TRACE_ID, $traceId);
    }

    public static function extractFromMessage(array $message): string
    {
        return trim((string) ($message['trace_id'] ?? ''));
    }
}

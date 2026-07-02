<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Stream;

use Swoolefy\Http\EventStream;
use Swoolefy\Http\ResponseOutput;

/**
 * SSE 响应助手 —— 打开 EventStream 并绑定 {@see StreamBridge}。
 */
final class SseResponse
{
    /**
     * 初始化 SSE 响应并绑定 StreamBridge。
     *
     * @param array<string, string> $headers 额外响应头
     */
    public static function open(ResponseOutput $response, array $headers = []): SseStreamSink
    {
        $eventStream = new EventStream($response, $headers);
        $sink = new SseStreamSink($eventStream);
        StreamBridge::bind($sink);

        return $sink;
    }

    /** 解除绑定并结束 SSE 连接。 */
    public static function close(?SseStreamSink $sink = null): void
    {
        StreamBridge::unbind();

        if ($sink !== null && $sink->isOpen()) {
            $sink->end();
        }
    }
}

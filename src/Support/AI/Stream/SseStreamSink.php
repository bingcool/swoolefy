<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Stream;

use Swoolefy\Http\EventStream;

/**
 * SSE 流式输出 Sink，委托 {@see EventStream} 推送事件。
 */
final class SseStreamSink implements StreamSinkInterface
{
    public function __construct(
        private readonly EventStream $stream,
    ) {
    }

    /** {@inheritdoc} */
    public function publish(string $event, array $payload = []): bool
    {
        if (!$this->isOpen()) {
            return false;
        }

        return $this->stream->send($payload, event: $event, autoId: true);
    }

    /** {@inheritdoc} */
    public function isOpen(): bool
    {
        return $this->stream->isWritable();
    }

    public function stream(): EventStream
    {
        return $this->stream;
    }

    public function end(): void
    {
        $this->stream->end();
    }
}

<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Stream;

/**
 * 内存收集 Sink —— 单测与 CLI 调试用。
 */
final class CollectingStreamSink implements StreamSinkInterface
{
    /** @var list<array{event: string, payload: array<string, mixed>}> */
    private array $events = [];

    /** {@inheritdoc} */
    public function publish(string $event, array $payload = []): bool
    {
        $this->events[] = ['event' => $event, 'payload' => $payload];

        return true;
    }

    /** {@inheritdoc} */
    public function isOpen(): bool
    {
        return true;
    }

    /** @return list<array{event: string, payload: array<string, mixed>}> */
    public function events(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}

<?php

declare(strict_types=1);

namespace Swoolefy\Support\AI\Stream;

/**
 * 流式事件输出目标（SSE / WebSocket / 内存收集）。
 */
interface StreamSinkInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function publish(string $event, array $payload = []): bool;

    public function isOpen(): bool;
}

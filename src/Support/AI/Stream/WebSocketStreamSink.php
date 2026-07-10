<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

declare(strict_types=1);

namespace Swoolefy\Support\AI\Stream;

/**
 * WebSocket 流式 Sink（Phase 2 占位实现）。
 *
 * 生产环境可注入 Swoole WebSocket push 闭包；当前 publish 为 no-op。
 */
final class WebSocketStreamSink implements StreamSinkInterface
{
    /**
     * @param (callable(string, array<string, mixed>): bool|null)|null $publisher
     */
    public function __construct(
        private $publisher = null,
    ) {
    }

    /** {@inheritdoc} */
    public function publish(string $event, array $payload = []): bool
    {
        if ($this->publisher === null) {
            return false;
        }

        return (bool) ($this->publisher)($event, $payload);
    }

    /** {@inheritdoc} */
    public function isOpen(): bool
    {
        return $this->publisher !== null;
    }
}

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

use Swoolefy\Core\Coroutine\Context as SwooleContext;

/**
 * 协程级 Stream Sink 绑定 —— 连接 Workflow EventBus 与 AINode token 流。
 *
 * 同一请求协程内 bind 一次，WorkflowEngine / AINode 通过 emit() 推送事件。
 */
final class StreamBridge
{
    private const KEY_SINK = 'swoolefy.support.ai.stream.sink';

    public static function bind(StreamSinkInterface $sink): void
    {
        $context = self::context();
        if ($context === null) {
            return;
        }

        $context[self::KEY_SINK] = $sink;
    }

    public static function unbind(): void
    {
        $context = self::context();
        if ($context === null) {
            return;
        }

        unset($context[self::KEY_SINK]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function emit(string $event, array $payload = []): bool
    {
        $sink = self::current();
        if ($sink === null) {
            return false;
        }

        return $sink->publish($event, $payload);
    }

    public static function current(): ?StreamSinkInterface
    {
        $context = self::context();
        if ($context === null) {
            return null;
        }

        $sink = $context[self::KEY_SINK] ?? null;

        return $sink instanceof StreamSinkInterface ? $sink : null;
    }

    private static function context(): ?\ArrayObject
    {
        try {
            $context = SwooleContext::getContext();
        } catch (\Throwable) {
            return null;
        }

        return $context instanceof \ArrayObject ? $context : null;
    }
}

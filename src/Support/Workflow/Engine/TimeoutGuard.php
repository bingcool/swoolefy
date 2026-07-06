<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Exception\WorkflowTimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 协程超时守卫 —— 包装节点执行，防止 LLM / MCP 调用阻塞 Worker。
 *
 * Swoole 协程内使用 Channel + 子协程超时；非协程 CLI 直接同步执行（不强制超时）。
 */
final class TimeoutGuard
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(callable $callback, float $timeoutSeconds): mixed
    {
        if ($timeoutSeconds <= 0) {
            return $callback();
        }

        if (Coroutine::getCid() < 0) {
            return $callback();
        }

        $channel = new Channel(1);

        Coroutine::create(static function () use ($callback, $channel): void {
            try {
                $channel->push(['ok', $callback()]);
            } catch (\Throwable $e) {
                $channel->push(['err', $e]);
            }
        });

        $payload = $channel->pop($timeoutSeconds);
        if ($payload === false) {
            throw new WorkflowTimeoutException(
                sprintf('Operation timed out after %.2f seconds', $timeoutSeconds),
            );
        }

        if ($payload[0] === 'err') {
            throw $payload[1];
        }

        return $payload[1];
    }
}

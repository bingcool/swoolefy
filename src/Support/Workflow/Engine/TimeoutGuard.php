<?php

declare(strict_types=1);

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Support\Workflow\Exception\WorkflowTimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 协程超时守卫 —— 包装节点执行，防止 LLM / MCP 调用阻塞 Worker。
 *
 * Swoole 协程内使用 Channel + 子协程超时；超时后尽量 cancel 子协程，避免泄漏。
 * 非协程 CLI 直接同步执行（不强制超时）。
 *
 * 注意：cancel 依赖子协程在挂起点（IO / sleep）响应；纯 CPU 忙循环仍可能短暂继续。
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

        $cid = Coroutine::create(static function () use ($callback, $channel): void {
            try {
                $channel->push(['ok', $callback()]);
            } catch (\Throwable $e) {
                // 被 cancel 时也可能走到这里；Channel 已 close 则 push 失败可忽略
                if (!$channel->push(['err', $e])) {
                    return;
                }
            }
        });

        $payload = $channel->pop($timeoutSeconds);
        if ($payload === false) {
            if (is_int($cid) && $cid > 0 && Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
            $channel->close();

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

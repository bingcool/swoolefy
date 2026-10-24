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

namespace Swoolefy\Support\Workflow\Engine;

use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\EventApp;
use Swoolefy\Support\AI\Stream\StreamBridge;
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
     * 原始超时执行：子协程不继承 Context / Application / StreamBridge。
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function run(callable $callback, float $timeoutSeconds): mixed
    {
        return self::execute($callback, $timeoutSeconds);
    }

    /**
     * 带请求上下文的超时执行：子协程恢复 Context 标量快照、StreamBridge sink，
     * 并在父协程有 Application 时用 EventApp 创建独立协程应用，保证 DB/Redis 解析器与 token 流可用。
     *
     * 组件实例不复用父协程的 App，避免超时取消时把父请求的容器提前 end。
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function runWithContext(callable $callback, float $timeoutSeconds): mixed
    {
        if ($timeoutSeconds <= 0 || Coroutine::getCid() < 0) {
            return $callback();
        }

        $contextData = Context::snapshot();
        $streamSink = StreamBridge::current();
        $hasApplication = Application::getApp() !== null;

        return self::execute(
            static function () use ($callback, $contextData, $streamSink, $hasApplication): mixed {
                foreach ($contextData as $key => $value) {
                    Context::set((string) $key, $value);
                }
                if ($streamSink !== null) {
                    StreamBridge::bind($streamSink);
                }

                if (!$hasApplication) {
                    return $callback();
                }

                $result = null;
                EventApp::run(static function () use ($callback, &$result): void {
                    $result = $callback();
                });

                return $result;
            },
            $timeoutSeconds,
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private static function execute(callable $callback, float $timeoutSeconds): mixed
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

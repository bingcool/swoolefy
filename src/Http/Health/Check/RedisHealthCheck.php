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

namespace Swoolefy\Http\Health\Check;

use Swoolefy\Core\Application;
use Swoolefy\Http\Health\HealthCheckInterface;
use Swoolefy\Http\Health\HealthCheckResult;
use Swoolefy\Library\Redis\RedisConnection;
use Throwable;

/**
 * Redis 连通性检查：对 Application 组件执行 PING。
 *
 * ## 技术点
 * - 组件常为协程包装，需 `getObject()` 取出真正的 {@see RedisConnection}
 * - phpredis / Predis 的 ping 返回值形态不同（true / "+PONG" / "PONG"），需兼容判断
 * - 异常一律转为 ok=false，保证探针返回 503 而非未捕获 500
 *
 * 推荐挂在 **readiness**，不要挂 liveness。
 */
final class RedisHealthCheck implements HealthCheckInterface
{
    /**
     * @param string $component      Application 组件名（默认 `redis`）
     * @param string $name           出现在 JSON checks[].name（可与 component 不同）
     * @param float  $timeoutSeconds 客户端读超时（秒）；与探针 TimeoutGuard 对齐
     */
    public function __construct(
        private readonly string $component = 'redis',
        private readonly string $name = 'redis',
        private readonly float $timeoutSeconds = 2.0,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * PING Redis；异常只返回错误分类，不暴露 DSN/凭证。
     */
    public function check(): HealthCheckResult
    {
        $started = microtime(true);
        try {
            $redis = $this->resolveRedis();
            $this->applyReadTimeout($redis);
            // 经 Redis / Predis 的 __call 转发到扩展客户端
            $pong = $redis->ping();
            $ok = $pong === true
                || $pong === '+PONG'
                || $pong === 'PONG'
                || (is_string($pong) && str_contains(strtoupper($pong), 'PONG'));

            return new HealthCheckResult(
                name: $this->name(),
                ok: $ok,
                message: $ok ? 'up' : 'unexpected_ping',
                meta: [
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'error_category' => $ok ? 'ok' : 'unexpected_response',
                ],
            );
        } catch (Throwable) {
            // 不把连接串/异常原文写入响应
            return new HealthCheckResult(
                name: $this->name(),
                ok: false,
                message: 'dependency_error',
                meta: [
                    'duration_ms' => round((microtime(true) - $started) * 1000, 2),
                    'error_category' => 'dependency_error',
                ],
            );
        }
    }

    /**
     * 尽量为底层客户端设置读超时，避免挂死（TimeoutGuard 仍是上层硬预算）。
     */
    private function applyReadTimeout(object $redis): void
    {
        if ($this->timeoutSeconds <= 0) {
            return;
        }
        $seconds = (int) max(1, (int) ceil($this->timeoutSeconds));
        if ($redis instanceof \Redis && defined('Redis::OPT_READ_TIMEOUT')) {
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, $seconds);
        }
    }

    /**
     * 解析 RedisConnection：兼容「直接实例」与「ContainerObjectDto::getObject()」。
     *
     * @throws \RuntimeException 应用未启动或组件类型错误
     */
    private function resolveRedis(): RedisConnection
    {
        $app = Application::getApp();
        if ($app === null) {
            throw new \RuntimeException('Application is not available');
        }

        $component = $app->get($this->component);
        if (is_object($component) && method_exists($component, 'getObject')) {
            $redis = $component->getObject();
        } else {
            $redis = $component;
        }

        if (!$redis instanceof RedisConnection) {
            throw new \RuntimeException(sprintf(
                'health redis component "%s" is not a RedisConnection',
                $this->component,
            ));
        }

        return $redis;
    }
}

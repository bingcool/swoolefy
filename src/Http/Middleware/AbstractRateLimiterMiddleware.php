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

namespace Swoolefy\Http\Middleware;

use Swoolefy\Core\Application;
use Swoolefy\Exception\RateLimitExceededException;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RateLimit\RateLimitConfig;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Http\RouteOption;
use Swoolefy\Library\RateLimit\DurationLimiter;
use Swoolefy\Library\Redis\RedisConnection;

/**
 * Redis 滑动窗口限流中间件基类。
 *
 * ## 为何每请求 new DurationLimiter
 * `DurationLimiter` 通过 `setRateKey` / `setLimitParams` 持有可变状态。
 * 若复用容器里的单例（如 Test 的 `rateLimit` 组件），协程并发会互相覆盖 key → 串限流。
 * 因此这里**每次 handle 新建**实例，只共享底层 Redis 连接。
 *
 * ## 配额优先级（高 → 低）
 * 1. {@see RouteOption::withRateLimiterMiddleware()} 写入 RequestInput 的 limit/window
 * 2. {@see RateLimitConfig::resolveRouteLimits()} 按 path 匹配 `routes`
 * 3. {@see RateLimitConfig::defaultLimitNum()} / {@see RateLimitConfig::defaultWindowSeconds()}
 *
 * ## 算法
 * 委托 {@see DurationLimiter}（Redis ZSET + Lua 滑动窗口），与
 * `Test\Controller\RateLimitController` 手写 Demo 同源。
 *
 * @see ApiRateLimiterMiddleware
 * @see ApiUserRateLimiterMiddleware
 */
abstract class AbstractRateLimiterMiddleware implements RateLimiterMiddlewareInterface
{
    /**
     * 必须零参：供 HttpRoute `new $middleware()`。
     * Redis / 配置均在 {@see handle()} 内惰性解析。
     */
    public function __construct()
    {
    }

    /**
     * 路由管道入口：未超限则放行；超限抛 {@see RateLimitExceededException}（默认 429）。
     *
     * 成功或失败路径均可按配置写入 `X-RateLimit-*`；失败额外写 `Retry-After`。
     * 异常处理新建 ResponseOutput 时，已写入的 swooleResponse header 通常仍保留。
     *
     * @throws RateLimitExceededException 窗口内请求数已达上限
     * @throws SystemException              Redis 组件类型错误等内部故障（500）
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        // RateLimitConfig: rate_limit.php
        $config = $this->config();

        // 总开关关闭：不访问 Redis，直接放行（便于压测或临时关闭）
        if (!$config->enabled()) {
            return true;
        }

        // 子类钩子：例如用户维在「未登录且 skip」时不计数
        if ($this->shouldSkip($requestInput, $config)) {
            return true;
        }

        [$limitNum, $windowSeconds] = $this->resolveLimits($requestInput, $config);

        // key_prefix（配置） + buildRateKey（维度）→ DurationLimiter 再加 _rate_limit:
        $logicalKey = $config->keyPrefix() . $this->buildRateKey($requestInput);

        $limiter = new DurationLimiter($this->resolveRedis($config));
        $limiter->setRateKey($logicalKey);
        $limiter->setLimitParams($limitNum, $windowSeconds);

        // isLimit()===false：本次已计入窗口且未超限；true：已满，本次未计入
        if (!$limiter->isLimit()) {
            if ($config->addHeaders()) {
                // getCurrentCount 含刚写入的本次请求，故 remaining = limit - count
                $remaining = max(0, $limitNum - $limiter->getCurrentCount());
                $this->writeLimitHeaders($responseOutput, $limitNum, $remaining, $windowSeconds);
            }

            return true;
        }

        // 已限流：Remaining=0，并提示客户端多久后可重试（秒）
        if ($config->addHeaders()) {
            $this->writeLimitHeaders($responseOutput, $limitNum, 0, $windowSeconds);
            $responseOutput->withHeader('Retry-After', (string) $windowSeconds);
        }

        throw new RateLimitExceededException($config->message(), $config->httpStatus());
    }

    /**
     * 是否跳过本次限流检查。
     *
     * 基类默认不跳过；{@see ApiUserRateLimiterMiddleware} 可在
     * `skip_when_unauthenticated=true` 且无本地验票用户时返回 true。
     */
    protected function shouldSkip(RequestInput $requestInput, RateLimitConfig $config): bool
    {
        unset($requestInput, $config);

        return false;
    }

    /**
     * 加载应用限流配置。单测可在子类覆盖为 {@see RateLimitConfig::fromArray()}。
     */
    protected function config(): RateLimitConfig
    {
        return RateLimitConfig::load();
    }

    /**
     * 解析本请求的 (limitNum, windowSeconds)。
     *
     * RouteOption 经 HttpRoute 在跑中间件前写入：
     * - {@see RouteOption::API_LIMIT_NUM_KEY}
     * - {@see RouteOption::API_LIMIT_WINDOW_SIZE_TIME_KEY}
     * 仅当两者都 > 0 时采用（避免「只配了一侧」的脏数据）。
     *
     * @return array{0: int, 1: int} [窗口内最大次数, 窗口秒数]
     */
    protected function resolveLimits(RequestInput $requestInput, RateLimitConfig $config): array
    {
        $routeLimit = (int) $requestInput->getValue(RouteOption::API_LIMIT_NUM_KEY);
        $routeWindow = (int) $requestInput->getValue(RouteOption::API_LIMIT_WINDOW_SIZE_TIME_KEY);
        if ($routeLimit > 0 && $routeWindow > 0) {
            return [$routeLimit, $routeWindow];
        }

        // 其次：Config routes 按 path 匹配
        $path = $this->normalizePath($requestInput);
        $matched = $config->resolveRouteLimits($path);
        if ($matched !== null) {
            return [$matched['limit_num'], $matched['window_seconds']];
        }

        // 最后：全局默认
        return [$config->defaultLimitNum(), $config->defaultWindowSeconds()];
    }

    /**
     * 规范化请求 path，用于 routes 匹配与 Redis key。
     *
     * 优先 `getRequestUri()` 的 path 部分（去掉 query）；失败则回退 `path()`。
     * 结果始终以 `/` 开头，避免 key 因有无斜杠而不稳定。
     */
    protected function normalizePath(RequestInput $requestInput): string
    {
        $uri = $requestInput->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/' . trim($requestInput->path(), '/');
            if ($path === '/') {
                return '/';
            }
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * HTTP 方法大写（GET/POST…），保证同一资源不同写法落到同一 key 段。
     */
    protected function requestMethod(RequestInput $requestInput): string
    {
        return strtoupper((string) $requestInput->getMethod());
    }

    /**
     * 从 Application 取出 RedisConnection。
     *
     * Test/生产常见形态：`get('redis')` 返回带 `getObject()` 的协程包装；
     * 也兼容直接返回 {@see RedisConnection} 实例。
     *
     * @throws SystemException 组件缺失或类型不对（配置错误，非客户端限流）
     */
    protected function resolveRedis(RateLimitConfig $config): RedisConnection
    {
        $name = $config->redisComponent();
        $component = Application::getApp()->get($name);
        if (is_object($component) && method_exists($component, 'getObject')) {
            $redis = $component->getObject();
        } else {
            $redis = $component;
        }

        if (!$redis instanceof RedisConnection) {
            throw new SystemException(
                sprintf('RateLimit redis component "%s" is not a RedisConnection', $name),
                500,
            );
        }

        return $redis;
    }

    /**
     * 写入标准限流响应头（不结束响应）。
     *
     * | Header | 含义 |
     * |--------|------|
     * | X-RateLimit-Limit | 窗口内最大允许次数 |
     * | X-RateLimit-Remaining | 窗口内剩余次数 |
     * | X-RateLimit-Window | 窗口秒数 |
     */
    protected function writeLimitHeaders(
        ResponseOutput $responseOutput,
        int $limitNum,
        int $remaining,
        int $windowSeconds,
    ): void {
        $responseOutput->withHeader('X-RateLimit-Limit', (string) $limitNum);
        $responseOutput->withHeader('X-RateLimit-Remaining', (string) $remaining);
        $responseOutput->withHeader('X-RateLimit-Window', (string) $windowSeconds);
    }
}

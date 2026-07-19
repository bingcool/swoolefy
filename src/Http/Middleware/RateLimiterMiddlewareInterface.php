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

use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Http\RequestInput;

/**
 * HTTP 限流中间件契约。
 *
 * ## 约束
 * - 实现类必须**零参构造**：{@see \Swoolefy\Http\HttpRoute} 对中间件执行 `new $middleware()`，不支持 DI。
 * - Redis / 开关 / 默认配额从 {@see \Swoolefy\Http\RateLimit\RateLimitConfig}（`Config/rate_limit.php`）读取。
 * - 单路由配额可通过 {@see \Swoolefy\Http\RouteOption::withRateLimiterMiddleware()} 注入（优先于配置文件）。
 *
 * ## 实现分层
 * | 类 | 维度 |
 * |----|------|
 * | {@see ApiRateLimiterMiddleware} | 路由（method + path）全站共享配额 |
 * | {@see ApiUserRateLimiterMiddleware} | 路由 + 用户（或匿名 IP）独立配额 |
 *
 * {@see handle()} 继承自 {@see RouteMiddlewareInterface}，由路由管道在 Controller 前调用。
 */
interface RateLimiterMiddlewareInterface extends RouteMiddlewareInterface
{
    /**
     * 构造滑动窗口使用的「逻辑键」片段。
     *
     * 最终写入 Redis 的 key 形如：
     * `{DurationLimiter::_rate_limit:}{config.key_prefix}{本方法返回值}`
     *
     * 本方法**不要**自行拼接 `_rate_limit:`；也不要在返回值里混入配额数字（配额走 setLimitParams）。
     *
     * @param RequestInput $requestInput 当前请求（可读 method / uri / ClientIP 等）
     *
     * @return string 稳定、可复现的逻辑键，例如 `api:GET:/api/v1/orders`
     */
    public function buildRateKey(RequestInput $requestInput): string;
}

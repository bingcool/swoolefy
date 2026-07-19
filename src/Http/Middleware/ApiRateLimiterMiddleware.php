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

use Swoolefy\Http\RequestInput;

/**
 * API **路由维度**限流中间件。
 *
 * 同一 `HTTP 方法 + path` 的所有客户端共享一个滑动窗口配额（全站该接口总量）。
 * 适合：公开搜索、列表、回调入口等「按接口保护」的场景。
 *
 * ## 挂载方式
 * ```php
 * use Swoolefy\Http\Middleware\ApiRateLimiterMiddleware;
 *
 * // 推荐：RouteOption 显式配额（优先于 Config）
 * Route::get('/api/v1/orders', [...])
 *     ->withRateLimiterMiddleware(ApiRateLimiterMiddleware::class, 100, 60);
 *
 * // 或挂 group middleware，配额走 Config/rate_limit.php 的 default / routes
 * Route::group(['middleware' => [ApiRateLimiterMiddleware::class]], function () { ... });
 * ```
 *
 * ## Redis 逻辑键
 * `{key_prefix}api:{METHOD}:{path}`
 * 例：`http:api:GET:/api/rate-test-api`（再经 DurationLimiter 加 `_rate_limit:`）
 *
 * @see AbstractRateLimiterMiddleware 配额解析与 Redis 调用
 * @see ApiUserRateLimiterMiddleware 需要按用户隔离时用此类
 */
class ApiRateLimiterMiddleware extends AbstractRateLimiterMiddleware
{
    /**
     * 构造路由维逻辑键：只依赖 method + path，与登录态无关。
     *
     * 注意：query string 不参与 key（已在 {@see normalizePath()} 去掉），
     * 避免 `?page=1` / `?page=2` 绕过同一接口限流。
     */
    public function buildRateKey(RequestInput $requestInput): string
    {
        $method = $this->requestMethod($requestInput);
        $path = $this->normalizePath($requestInput);

        // 固定三段，便于运维按前缀扫描 Redis key
        return 'api:' . $method . ':' . $path;
    }
}

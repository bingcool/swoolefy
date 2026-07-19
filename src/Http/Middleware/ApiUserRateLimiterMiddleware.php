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

use Swoolefy\Http\RateLimit\RateLimitConfig;
use Swoolefy\Http\RequestInput;
use Swoolefy\Support\FrameworkContext;

/**
 * API **路由 + 用户**维度限流中间件。
 *
 * 同一 `方法 + path + subject` 独立配额：用户 A 打满不影响用户 B。
 * 适合：写操作、导出、按账号防刷等。
 *
 * ## 挂载建议
 * 放在 {@see AuthenticateMiddleware} **之后**，以便
 * {@see FrameworkContext::user()} 已有本地验票身份：
 * ```php
 * Route::get('/api/v1/me/orders', [
 *     'middleware' => [AuthenticateMiddleware::class],
 *     'dispatch_route' => [...],
 * ])->withRateLimiterMiddleware(
 *     ApiUserRateLimiterMiddleware::class,
 *     30,
 *     60,
 *     AuthenticateMiddleware::class, // runAfter：保证先鉴权再限流
 * );
 * ```
 *
 * ## subject 规则（见 {@see resolveSubject()}）
 * | 条件 | subject |
 * |------|---------|
 * | 已 setUser | `u:{userId}` |
 * | 匿名且开启用 IP | `ip:{clientIp}` |
 * | 其他 | `anon:{anonymous_key}` |
 *
 * ## Redis 逻辑键
 * `{key_prefix}api_user:{METHOD}:{path}:{subject}`
 *
 * @see ApiRateLimiterMiddleware 仅按接口总量限流时用此类
 */
final class ApiUserRateLimiterMiddleware extends AbstractRateLimiterMiddleware
{
    /**
     * 构造「路由 + 用户」逻辑键。
     *
     * subject 由 {@see resolveSubject()} 决定；同一 path 下不同用户不会共用窗口。
     */
    public function buildRateKey(RequestInput $requestInput): string
    {
        $method = $this->requestMethod($requestInput);
        $path = $this->normalizePath($requestInput);
        $subject = $this->resolveSubject($requestInput, $this->config());

        return 'api_user:' . $method . ':' . $path . ':' . $subject;
    }

    /**
     * 配置 `user.skip_when_unauthenticated=true` 且当前无本地验票用户时跳过限流。
     *
     * 仅看 {@see FrameworkContext::user()}，**不**把透传头 `x-user-id` 当成已登录，
     * 避免网关伪造头绕过「必须登录才计数」的语义。
     */
    protected function shouldSkip(RequestInput $requestInput, RateLimitConfig $config): bool
    {
        unset($requestInput);
        if (!$config->skipWhenUnauthenticated()) {
            return false;
        }

        $user = FrameworkContext::user();

        return $user === null || $user->userId === '';
    }

    /**
     * 解析写入 Redis key 的用户主体标识。
     *
     * 优先本地 AuthUser（中间件验票结果）；其次 ClientIP；最后配置的 anonymous_key。
     * 不用 {@see FrameworkContext::getUserId()} 的 Header 兜底，防止未验票却按「用户」分桶。
     */
    private function resolveSubject(RequestInput $requestInput, RateLimitConfig $config): string
    {
        $user = FrameworkContext::user();
        if ($user !== null && $user->userId !== '') {
            return 'u:' . $user->userId;
        }

        // 匿名：按 IP 分桶，比全局 guest 更细，也更适合防刷
        if ($config->useClientIpWhenAnonymous()) {
            $ip = (string) $requestInput->getClientIP();
            if ($ip !== '') {
                return 'ip:' . $ip;
            }
        }

        // 拿不到 IP（极端环境）时退化为固定 anon 键——该桶会较粗，仅作兜底
        return 'anon:' . $config->anonymousKey();
    }
}

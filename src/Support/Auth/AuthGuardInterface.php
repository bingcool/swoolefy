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

namespace Swoolefy\Support\Auth;

/**
 * 凭证签发与解析的统一入口（Guard）。
 *
 * HTTP 中间件、WebSocket 握手 callback、登录签发共用本接口，
 * 默认实现为 {@see JwtAuthGuard}；组件名 `auth.guard`。
 *
 * ## 语义约定（调用方必须遵守）
 * | 方法 | 返回 / 异常 |
 * |------|-------------|
 * | authenticate → null | 无凭证（匿名）；强制登录中间件 → 401；可选登录 → 放行 |
 * | authenticate → AuthUser | 验票成功 → FrameworkContext::setUser |
 * | authenticate 抛 AuthException | 有凭证但非法/过期 → 401，不可当匿名 |
 * | generateToken → string | 签发与 authenticate 对称的凭证；密钥未配置 → AuthException |
 *
 * Guard **配置实例**可进程内单例复用；**禁止**在 Guard 上缓存「当前请求用户」。
 *
 * @see JwtAuthGuard
 * @see docs/Auth.md
 */
interface AuthGuardInterface
{
    /**
     * 解析凭证并返回身份。
     *
     * @param array{token?: string, bearer?: string} $credentials
     *        token / bearer 二选一，空字符串视为匿名
     * @return AuthUser|null null = 匿名（由调用方决定是否拒绝）
     *
     * @throws AuthException 凭证存在但非法 / 过期 / 签名错误
     */
    public function authenticate(array $credentials): ?AuthUser;

    /**
     * 为身份签发凭证（与 {@see authenticate()} 使用同一套密钥 / claim 映射）。
     *
     * 登录成功、刷新 token 等场景调用；生成的字符串可原样放入
     * `Authorization: Bearer …` 再交给 authenticate。
     *
     * @param AuthUser $user       待写入凭证的身份（userId / roles / tenantId / claims）
     * @param int|null $ttlSeconds 过期秒数；null 时用配置 jwt.ttl_seconds（默认 3600）
     *
     * @throws AuthException 密钥未配置或签发失败
     */
    public function generateToken(AuthUser $user, ?int $ttlSeconds = null): string;
}

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
 * 凭证 → 身份 的统一入口（Guard）。
 *
 * HTTP 中间件、WebSocket 握手 callback、（可选）脚本侧验票共用本接口，
 * 默认实现为 {@see JwtAuthGuard}；组件名 `auth.guard`。
 *
 * ## 语义约定（调用方必须遵守）
 * | 返回 | 含义 | 典型处理 |
 * |------|------|----------|
 * | null | 无凭证（匿名） | 强制登录中间件 → 401；可选登录 → 放行 |
 * | AuthUser | 验票成功 | FrameworkContext::setUser |
 * | 抛 AuthException | 有凭证但非法/过期/签名错 | 一律 401，不可当匿名放行 |
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
}

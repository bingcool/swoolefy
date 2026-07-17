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

namespace Swoolefy\Support;

use Swoole\Http\Status;
use Swoolefy\Core\Coroutine\Context as SwooleContext;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthUser;
use Swoolefy\Support\HeaderPropagation\HeaderContext;
use Swoolefy\Support\HeaderPropagation\HeaderPropagator;

/**
 * 框架请求上下文门面：服务间透传头 + 本地验票身份（唯一身份入口）
 * 注意！！！：这个类主要获取的是服务之间透传请求投的关键信息和登录认证信息。也不提供set，不要主动设置其他业务在协程直接的传递。
 *
 * 如果需要在父协程，子协程直接透传业务的关键数据，请使用 @see \Swoolefy\Core\Coroutine\Context::set() 设置,
 * 然后使用 @see \Swoolefy\Core\Coroutine\Context::get()
 *
 * ## 两类数据
 * | 类型 | 存储 | 可信度 |
 * |------|------|--------|
 * | 透传头 x-user-id / x-tenant-id / trace… | {@see HeaderContext} | 半可信（网关已验时可直接用） |
 * | 本地 AuthUser | 协程 Context 键 {@see AUTH_USER_KEY}（**array 快照**） | 可信（本进程 Guard 验过） |
 *
 * ## getUserId / getTenantId 优先级
 * ```text
 * 1. FrameworkContext::user()     ← setUser 之后（Auth 优先）
 * 2. HeaderContext 白名单头       ← 未 setUser 时与改造前行为一致
 * 3. $default
 * ```
 * 因此：入口服务必须挂 AuthenticateMiddleware；内部服务可只透传头。
 *
 * ## 协程安全
 * setUser 只写 array；goApp 会透传该 array，子协程 user() 可还原。
 * 禁止 Context 存 AuthUser 对象（goApp 会跳过 object）。
 *
 * Neuron / TenantScope / Capability 等已调用 getUserId()/getTenantId()，
 * Phase 1 后自动变为「Auth 优先」，未 setUser 时兼容旧行为。
 *
 * @see HeaderContext
 * @see HeaderPropagator
 * @see docs/Auth.md
 */
final class FrameworkContext
{
    /**
     * 协程 Context 中身份快照的键名。
     * 值必须是 AuthUser::toArray()，禁止放 AuthUser 对象。
     */
    private const AUTH_USER_KEY = '__swoolefy_auth_user';

    /**
     * 读取透传头（小写规范化由 HeaderContext 处理）
     */
    public static function get(string $name, ?string $default = null): ?string
    {
        return HeaderContext::get($name, $default);
    }

    /**
     * 写入本地登录态，并回写白名单透传头，使出站 HeaderPropagator 与身份对齐。
     *
     * - Context：array 快照（goApp 可拷贝）
     * - Header：x-user-id；有 tenant 时再写 x-tenant-id
     * - 不写 Authorization（透传层刻意不传播凭证头）
     */
    public static function setUser(AuthUser $user): void
    {
        SwooleContext::set(self::AUTH_USER_KEY, $user->toArray());

        HeaderContext::put(HeaderPropagator::HEADER_USER_ID, $user->userId);
        if ($user->tenantId !== null && $user->tenantId !== '') {
            HeaderContext::put(HeaderPropagator::HEADER_TENANT_ID, $user->tenantId);
        }
    }

    /**
     * 当前协程已验票用户；未 setUser 或快照损坏时返回 null。
     * 内部服务「只透传头」场景下通常为 null，请用 getUserId()。
     */
    public static function user(): ?AuthUser
    {
        $data = SwooleContext::get(self::AUTH_USER_KEY);
        if (!is_array($data) || ($data['userId'] ?? '') === '') {
            return null;
        }

        return AuthUser::fromArray($data);
    }

    /**
     * 需要登录的业务入口：无用户则 AuthException(401)。
     *
     * @throws AuthException
     */
    public static function userOrFail(): AuthUser
    {
        $user = self::user();
        if ($user === null) {
            throw new AuthException('Unauthenticated', Status::UNAUTHORIZED);
        }

        return $user;
    }

    /**
     * 清除本地 Auth 快照。
     * 注意：不自动清掉 HeaderContext 中已回写的 x-user-id（请求结束由 HttpServer defer clear）。
     */
    public static function clearUser(): void
    {
        SwooleContext::delete(self::AUTH_USER_KEY);
    }

    /**
     * 是否已 setUser（本地验票身份存在）
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * 服务之间请求的关键请求头
     */
    public static function headerContext(): array
    {
        return HeaderContext::all();
    }

    /**
     * 语义化用户 id：Auth 优先，透传头兜底。
     * Body/Query 的 userId/uid **不是**身份来源，业务勿混用。
     */
    public static function getUserId(?string $default = null): ?string
    {
        return self::user()?->userId
            ?? self::get(HeaderPropagator::HEADER_USER_ID, $default);
    }

    /** 语义化租户 id：Auth 优先，透传头兜底。 */
    public static function getTenantId(?string $default = null): ?string
    {
        return self::user()?->tenantId
            ?? self::get(HeaderPropagator::HEADER_TENANT_ID, $default);
    }

    public static function getTraceId(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_TRACE_ID, $default);
    }

    public static function getUserAgent(?string $default = null): ?string
    {
        return self::get(HeaderPropagator::HEADER_USER_AGENT, $default);
    }
}

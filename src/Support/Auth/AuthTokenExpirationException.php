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
 * 鉴权 / 认证失败异常。
 *
 * ## 为何继承 SystemException
 * App 异常处理通常按 `$throwable->getCode()` 写回 HTTP 状态。
 * 继承框架异常体系，保证中间件抛出后响应为 **401/403**，而不是被统一包装成 500。
 *
 * ## code 约定
 * | code | 场景 |
 * |------|------|
 * | 401 | 缺 token、非法/过期 JWT、未登录（Unauthenticated） |
 * | 403 | 已登录但权限不足（一般由业务/HITL 的 Permission 异常承担；本类也可用） |
 * | 500 | 内部数据损坏（如 AuthUser::fromArray 缺 userId），非客户端凭证问题 |
 *
 * @see \Swoolefy\Http\Middleware\AuthenticateMiddleware
 * @see docs/Auth.md
 */
class AuthTokenExpirationException extends AuthException
{

}

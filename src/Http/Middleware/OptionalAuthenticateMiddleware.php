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
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\Auth\AuthException;

/**
 * 可选登录中间件(游客 + 登录用户都可以共同访问同一接口，遇到有Bearer token，就验证，验证不通过再抛异常401。无token(匿名访问)时，直接放行，可以以游客模式访问)
 * OptionalAuthenticateMiddleware与AuthenticateMiddleware强制登录类，拆成两个类，根据不同情况来设置路由中间件
 *
 * | 请求 | 行为 |
 * |------|------|
 * | 无 Authorization / 无 Bearer | 放行（匿名；getUserId 仍可读透传头） |
 * | 合法 Bearer | setUser 后放行 |
 * | 非法 / 过期 Bearer，或验票返回 null | 仍抛 AuthException(401)，不会静默当匿名 |
 * | Guard 内部非 Auth 异常 | AuthException(500)，禁止降级匿名 |
 *
 * 1、公开接口：两个登录中间件都不要挂。
 * 2、游客 + 登录用户都可以共同访问同一接口：路由挂 @see OptionalAuthenticateMiddleware
 * 3、一定要求需登录才能请求的接口：路由挂 @see AuthenticateMiddleware
 *
 * @see AuthenticateMiddleware
 * @see docs/Auth.md
 *
 */
final class OptionalAuthenticateMiddleware extends AuthenticateMiddleware
{
    /**
     * @throws AuthException 仅当携带了非法 token
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        $this->authenticate($requestInput, optional: true);

        return true;
    }
}

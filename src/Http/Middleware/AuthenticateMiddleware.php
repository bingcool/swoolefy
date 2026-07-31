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

use Swoole\Http\Status;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Core\RouteMiddlewareInterface;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthGuardInterface;
use Swoolefy\Support\FrameworkContext;

/**
 *
 *
 * AuthenticateMiddleware中间件强制要求登录才能访问。Bearer JWT登录验证中间件（缺失Bearer token或者验证token不通过都直接抛出异常，强制要求登录）。
 *
 * ## 路由挂载（推荐）
 * ```php
 * Route::group([
 *     'middleware' => [AuthenticateMiddleware::class],
 * ], function () { ... });
 * ```
 *
 * ## 为何必须零参构造
 * {@see \Swoolefy\Http\HttpRoute} 对中间件执行 `new $middleware()`，**不支持构造器注入**。
 * Guard 在 {@see authenticate()} 内通过 `Application::getApp()->get('auth.guard')` 获取。
 * 可选登录请用 {@see OptionalAuthenticateMiddleware}，不要给本类加 `$optional` 构造参数。
 *
 * ## 行为
 * 1. 读 `Authorization: Bearer …`
 * 2. Guard 验票 → FrameworkContext::setUser
 * 3. 若有 tenantId，同步写协程键 `tenant_id`（兼容已有业务 Context）
 * 4. 缺 token / 验票失败 → AuthException(401)
 *
 * @see OptionalAuthenticateMiddleware
 * @see docs/Auth.md
 */
class AuthenticateMiddleware implements RouteMiddlewareInterface
{
    /** 必须零参，供 HttpRoute::new $middleware()。 */
    public function __construct()
    {
    }

    /**
     * 强制登录入口。
     *
     * @throws AuthException 401
     */
    public function handle(RequestInput $requestInput, ResponseOutput $responseOutput): bool
    {
        $this->authenticate($requestInput, optional: false);

        return true;
    }

    /**
     * 共享验票逻辑（子类 Optional 传 optional=true）。
     *
     * Optional 仅对「未携带 Token」匿名放行；携带 Token 时非法/过期/null 一律 401；
     * Guard 非 AuthException 内部异常映射 500，禁止降级为匿名。
     *
     * @param bool $optional true：无 token 直接 return；有非法 token 仍抛 401
     *
     * @throws AuthException
     */
    protected function authenticate(RequestInput $requestInput, bool $optional): void
    {
        // 进程内复用 Guard 配置实例；勿在 Guard 上缓存「当前用户」
        /** @var AuthGuardInterface $guard */
        $guard = Application::getApp()->get('auth.guard');

        $token = $this->bearerToken($requestInput);
        if ($token === '') {
            if ($optional) {
                // Optional 只放行缺失 Token，不得把验票失败当匿名
                return;
            }
            // 挂 AuthenticateMiddleware强制认证登录中间件，缺失token，直接抛异常，拒绝往下执行
            throw new AuthException('Missing bearer token', Status::UNAUTHORIZED);
        }

        try {
            $user = $guard->authenticate(['token' => $token]);
        } catch (AuthException $e) {
            // 非法 / 过期等凭证错误：强制与 Optional 均 401，不降级匿名
            throw $e;
        } catch (\Throwable $e) {
            // Guard 内部异常：500，禁止 Optional 静默放行
            throw new AuthException('Auth guard error', Status::INTERNAL_SERVER_ERROR, $e);
        }

        // 已携带 Token 却得到 null：视为验票失败（自定义 Guard 不得借 Optional 匿名化）
        if ($user === null) {
            throw new AuthException('Unauthenticated', Status::UNAUTHORIZED);
        }

        FrameworkContext::setUser($user);

        // 与部分 Bootstrap / 业务已依赖的 tenant_id Context 键对齐
        if ($user->tenantId !== null && $user->tenantId !== '') {
            Context::set('tenant_id', $user->tenantId);
        }
    }

    /**
     * 仅识别标准 Bearer 头；不从 Query/Body 取 token（避免把业务参数当凭证）。
     */
    private function bearerToken(RequestInput $requestInput): string
    {
        $authorization = (string) $requestInput->getHeaderParams('authorization', '');
        if (stripos($authorization, 'bearer ') === 0) {
            return trim(substr($authorization, 7));
        }

        return '';
    }
}

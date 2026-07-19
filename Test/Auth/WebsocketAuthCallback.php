<?php

declare(strict_types=1);

namespace Test\Auth;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthGuardInterface;
use Swoolefy\Support\FrameworkContext;
use Test\App;

/**
 * WebSocket 握手鉴权回调（与 HTTP 共用 JwtAuthGuard）。
 *
 * ## 配置
 * ```php
 * // Config/websocket.php（或 stub 生成后的配置）
 * 'auth' => [
 *     'enable' => true,
 *     'require_user_id' => true,
 *     'callback' => [Test\Auth\WebsocketAuthCallback::class, 'authenticate'],
 * ],
 * ```
 *
 * ## 为何用静态方法
 * {@see \Swoolefy\Websocket\WebsocketAuthenticator} 通过 `call_user_func($callback, $request, $token)` 调用，
 * **不会** `new` 再注入 Guard。故本类用静态方法，内部 `get('auth.guard')`。
 *
 * ## 安全要点
 * - **忽略** query `uid` / `user_id`（客户端可伪造）
 * - **禁止**返回 `true`（Authenticator 会回退读 query uid）
 * - 必须返回 `['user_id' => $user->userId]` 绑定连接身份
 * - 握手协程内 `setUser`，便于 onOpen / 首条业务读 FrameworkContext
 *
 * ## 客户端传 token
 * 1. `Authorization: Bearer <jwt>`
 * 2. Query `token` / `access_token`
 * 3. Header `Sec-WebSocket-Protocol`（部分浏览器限制）
 *
 * @see \Swoolefy\Websocket\WebsocketAuthenticator
 * @see docs/Auth.md
 */
final class WebsocketAuthCallback
{
    /**
     * 握手鉴权：验 JWT → 绑定 user_id → 写入 FrameworkContext。
     *
     * @param Request $request 握手请求（本实现故意不用 query uid，故参数可忽略）
     * @param string  $token   已由 WebsocketAuthenticator 从 Bearer/query/协议头提取
     *
     * @return array{user_id: string}|false false 表示拒绝（关闭码 1008）
     */
    public static function authenticate(Request $request, string $token): array|false
    {
        // 身份只来自 Guard，不用 $request->get['uid']
        unset($request);

        /** @var AuthGuardInterface $guard */
        $guard = App::getAuthGuard();

        try {
            $user = $guard->authenticate(['token' => $token]);
        } catch (AuthException) {
            return false;
        }
        if ($user === null) {
            return false;
        }

        // 与 HTTP 中间件一致：后续同协程可读 FrameworkContext::user()
        FrameworkContext::setUser($user);

        return ['user_id' => $user->userId];
    }
}

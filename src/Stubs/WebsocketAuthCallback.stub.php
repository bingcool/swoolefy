<?php

namespace __APP_NAMESPACE__\Auth;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Support\Auth\AuthException;
use Swoolefy\Support\Auth\AuthGuardInterface;
use Swoolefy\Support\FrameworkContext;

/**
 * WebSocket 握手鉴权回调（生产环境推荐接入点）。
 *
 * ## 配置
 *
 * ```php
 * // Config/websocket.php
 * 'auth' => [
 *     'enable' => true,
 *     'require_user_id' => true,
 *     // 必须是可 call_user_func 的静态方法或闭包（框架不会构造注入）
 *     'callback' => [__APP_NAMESPACE__\Auth\WebsocketAuthCallback::class, 'authenticate'],
 * ],
 * ```
 *
 * ## 客户端传参
 *
 * Header：`Authorization: Bearer YOUR_JWT`
 * 或 Query：`token` / `access_token`
 *
 * ## 安全
 *
 * - **禁止**用 query `uid` 作为身份；user_id 仅来自 Guard 验票结果
 * - **禁止**返回 `true`（Authenticator 会回退读 query uid）
 * - 必须返回 `['user_id' => '...']` 或 `false`
 *
 * @see \Swoolefy\Websocket\WebsocketAuthenticator
 * @see docs/Auth.md
 */
class WebsocketAuthCallback
{
    /**
     * 校验 JWT 并绑定连接身份；同时写入 FrameworkContext 供握手协程后续逻辑使用。
     *
     * @param Request $request 握手请求（勿用 query uid）
     * @param string  $token   已由框架从 Bearer / query / Sec-WebSocket-Protocol 提取
     *
     * @return array{user_id: string}|false false 表示拒绝连接（关闭码 1008）
     */
    public static function authenticate(Request $request, string $token): array|false
    {
        unset($request);

        /** @var AuthGuardInterface $guard */
        $guard = Application::getApp()->get('auth.guard');

        try {
            $user = $guard->authenticate(['token' => $token]);
        } catch (AuthException) {
            return false;
        }
        if ($user === null) {
            return false;
        }

        FrameworkContext::setUser($user);

        return ['user_id' => $user->userId];
    }
}

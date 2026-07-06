<?php

namespace __APP_NAMESPACE__\Auth;

use Swoole\Http\Request;

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
 *     'callback' => [__APP_NAMESPACE__\Auth\WebsocketAuthCallback::class, 'authenticate'],
 * ],
 * ```
 *
 * ## 客户端传参示例
 *
 * ```
 * ws://host:9508/socket.io/?EIO=4&transport=websocket&uid=user-1&token=YOUR_JWT
 * ```
 *
 * 或 Header：`Authorization: Bearer YOUR_JWT`
 *
 * ## 返回值
 *
 * - `false`：拒绝连接（关闭码 1008）
 * - `['user_id' => 'xxx']`：通过并绑定身份（推荐，可不从 query 读 uid）
 *
 * @see \Swoolefy\Websocket\WebsocketAuthenticator
 */
class WebsocketAuthCallback
{
    /**
     * 校验 token 并返回用户身份。
     *
     * @param Request $request 握手请求（可读 query、header）
     * @param string  $token   已由框架从 Bearer / query / Sec-WebSocket-Protocol 提取
     *
     * @return array{user_id: string}|bool false 表示拒绝
     */
    public function authenticate(Request $request, string $token)
    {
        if ($token === '') {
            return false;
        }

        // TODO: 替换为 JWT 解析、Session 校验或调用用户服务
        // 示例：仅接受 dev-token，且 query 须带 uid
        if ($token !== 'dev-token') {
            return false;
        }

        $userId = trim((string) ($request->get['uid'] ?? $request->get['user_id'] ?? ''));
        if ($userId === '') {
            return false;
        }

        return ['user_id' => $userId];
    }
}

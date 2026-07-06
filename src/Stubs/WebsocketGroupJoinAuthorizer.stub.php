<?php

namespace __APP_NAMESPACE__\Auth;

use Swoolefy\Websocket\Group\GroupJoinAuthorizerInterface;

/**
 * WebSocket 加组鉴权（邀请码 / 房间密码示例）。
 *
 * ## 配置
 *
 * ```php
 * 'group' => [
 *     'join_authorizer' => [__APP_NAMESPACE__\Auth\WebsocketGroupJoinAuthorizer::class, 'authorize'],
 * ],
 * ```
 *
 * ## 客户端示例（Socket.IO）
 *
 * ```javascript
 * socket.emit('group.join', { group: 'demo', invite_code: 'demo-invite' }, console.log);
 * socket.emit('group.join', { group: 'admin', password: 'admin-secret' }, console.log);
 * ```
 *
 * 鉴权失败时 ChatService::joinGroup 抛 InvalidArgumentException，Socket.IO ack 为 code=-1。
 *
 * @see GroupJoinAuthorizerInterface
 * @see \Swoolefy\Websocket\WebsocketConnectionManager::joinGroup()
 */
class WebsocketGroupJoinAuthorizer implements GroupJoinAuthorizerInterface
{
    /**
     * @param int    $fd     连接 fd
     * @param string $userId 握手绑定的 user_id（auth 开启后非空）
     * @param string $group  目标房间名
     * @param array  $params 客户端 join 请求体
     *
     * @return string|null null=允许；非空=拒绝原因（返回给客户端）
     */
    public function authorize(int $fd, string $userId, string $group, array $params): ?string
    {
        if ($userId === '') {
            return 'login required before joining group';
        }

        // public：开放房间
        if ($group === 'public') {
            return null;
        }

        // demo：需要邀请码
        if ($group === 'demo') {
            $invite = trim((string) ($params['invite_code'] ?? ''));
            if ($invite !== 'demo-invite') {
                return 'invalid invite_code';
            }

            return null;
        }

        // admin：需要房间密码
        if ($group === 'admin') {
            $password = trim((string) ($params['password'] ?? ''));
            if ($password !== 'admin-secret') {
                return 'invalid room password';
            }

            return null;
        }

        // 其他房间：可按业务查 DB 成员表后决定
        return null;
    }
}

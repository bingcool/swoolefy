<?php

namespace Swoolefy\Websocket\Group;

/**
 * 加入 WebSocket 小组前的业务鉴权接口。
 *
 * ## 调用链
 *
 * ```
 * 客户端 group.join / joinWebsocketGroup
 *   → ChatService::joinGroup($params)
 *   → WebsocketConnectionManager::joinGroup($fd, $group, $params)
 *   → GroupJoinAuthorizerFactory::authorize()（本接口）
 *   → 允许：写入本地 Table + Redis group 索引，返回 ['ok' => true, 'reason' => null]
 *   → 拒绝：返回 ['ok' => false, 'reason' => 拒绝原因]（协程安全，勿用进程级 static）
 * ```
 *
 * ## 典型用途
 *
 * - 校验用户是否为房间成员（查 DB）
 * - 验证邀请码 `invite_code`
 * - 验证房间密码 `password`
 * - 限制未登录连接加组（user_id 为空时拒绝）
 *
 * ## 配置
 *
 * ```php
 * // Config/websocket.php
 * 'group' => [
 *     'join_authorizer' => [App\Auth\WebsocketGroupJoinAuthorizer::class, 'authorize'],
 * ],
 * ```
 *
 * 未配置 join_authorizer 时默认允许所有加组（与旧版行为兼容）。
 *
 * @see GroupJoinAuthorizerFactory
 * @see WebsocketConnectionManager::joinGroup()
 */
interface GroupJoinAuthorizerInterface
{
    /**
     * 是否允许当前连接加入小组。
     *
     * @param int    $fd     连接 fd
     * @param string $userId 当前连接 user_id（auth 未开启时可能为空）
     * @param string $group  目标小组名
     * @param array  $params 客户端 join 请求体（如 invite_code、password、group）
     *
     * @return string|null null 表示允许；非空字符串为拒绝原因（会返回给客户端 / 写入 ack）
     */
    public function authorize(int $fd, string $userId, string $group, array $params): ?string;
}

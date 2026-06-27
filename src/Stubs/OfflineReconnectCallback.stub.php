<?php

namespace __APP_NAMESPACE__\Offline;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\Offline\OfflineReconnectHookInterface;

/**
 * 用户上线（重连）业务钩子示例。
 *
 * ## 触发时机
 *
 * WebsocketConnectionManager::open / bindUser
 *   → OfflineMessageCoordinator::onUserOnline()
 *   → [先] replay_on_reconnect 框架补推
 *   → [后] 本类 onReconnect()
 *
 * ## 典型扩展
 *
 * - 推送未读数 badge（`OfflineMessageCoordinator::countPending`）
 * - 同步会话列表 / 触发 IM 差量拉取
 * - 审计日志
 *
 * ## 完全自管补推
 *
 * 配置 `replay_on_reconnect=false`，在本类内：
 * ```php
 * $page = OfflineMessageCoordinator::pullPending($userId, 100);
 * // 自行 push + ackDelivered
 * ```
 */
class OfflineReconnectCallback implements OfflineReconnectHookInterface
{
    public function onReconnect(Server $server, int $fd, string $userId, int $replayedCount): void
    {
        // $replayedCount：框架已自动补推并成功 markDelivered 的条数
        // Log::info('user online', ['user_id' => $userId, 'replayed' => $replayedCount]);
    }
}

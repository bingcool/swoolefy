<?php

namespace Swoolefy\Websocket\Offline;

use Swoole\WebSocket\Server;

/**
 * 用户上线（重连）业务钩子。
 *
 * ## 执行顺序（onUserOnline 内）
 *
 * ```
 * 1. [可选] replay_on_reconnect → 框架从离线表拉 pending 并 server->push
 * 2. offline.on_reconnect 钩子 → 本接口 onReconnect()
 * ```
 *
 * ## 典型用途
 *
 * - 刷新未读 badge、同步会话列表
 * - 记录上线审计日志
 * - 触发业务侧增量同步（IM 全量/差量）
 *
 * ## 完全自管补推
 *
 * 若不想用框架默认补推，配置 `replay_on_reconnect=false`，
 * 在本钩子内自行 `fetchPending` + 推送 + `markDelivered`。
 *
 * @see OfflineMessageCoordinator::onUserOnline()
 */
interface OfflineReconnectHookInterface
{
    /**
     * @param int $replayedCount 框架默认补推并成功 markDelivered 的条数（自管模式下为 0）
     */
    public function onReconnect(Server $server, int $fd, string $userId, int $replayedCount): void;
}

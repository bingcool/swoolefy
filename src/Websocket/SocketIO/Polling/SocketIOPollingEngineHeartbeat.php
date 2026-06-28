<?php

namespace Swoolefy\Websocket\SocketIO\Polling;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;
use Swoolefy\Websocket\SocketIO\SocketIOSessionManager;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * Engine.IO long-polling 服务端心跳（主动发 ping `2`）。
 *
 * ## 背景
 *
 * | Transport   | 谁发 ping | 谁回 pong |
 * |-------------|-----------|-----------|
 * | WebSocket   | 通常客户端发 `2` | 服务端 `3`（{@see SocketIOHandler}） |
 * | long-polling | **服务端**发 `2` | 客户端 POST `3` |
 *
 * engine.io-client 在 open 包中读取 pingTimeout（默认 20s）：若 long-poll 长时间无出站包，
 * 会判定 transport error。配置里 pingInterval(25s) 可大于 pingTimeout(20s)，
 * 因此不能仅靠定时 tick 的首轮 ping，握手后须立即 enqueue 一包。
 *
 * ## 触发方式
 *
 * 1. **即时**：{@see SocketIOPollingHandler::handlePost} 在 namespace connect 成功后 enqueue ping
 * 2. **周期**：worker 0 上 goTick，间隔 min(ping_interval, ping_timeout - 2) 秒
 *
 * ping 写入 {@see SocketIOPollingOutboundStore}，由某 Worker 上阻塞的 GET long-poll 取走。
 *
 * @see SocketIOPollingHandler
 * @see WebsocketServer::workerStartInit  boot() 注册点
 */
class SocketIOPollingEngineHeartbeat
{
    /**
     * 在 worker 0 注册定时 ping（仅 worker 0，避免多 Worker 重复发）。
     *
     * tick 间隔刻意 ≤ pingTimeout，弥补 pingInterval > pingTimeout 的配置组合。
     */
    public static function boot(array $config): void
    {
        if (empty($config['socketio']['allow_polling'])) {
            return;
        }

        $socketio = is_array($config['socketio'] ?? null) ? $config['socketio'] : [];
        $pingInterval = max(1, (int) ($socketio['ping_interval'] ?? 25));
        $pingTimeout = max(1, (int) ($socketio['ping_timeout'] ?? 20));
        $tickSec = max(1, min($pingInterval, $pingTimeout - 2));

        goTick($tickSec * 1000, static function () use ($config): void {
            self::sendPings($config);
        });
    }

    /**
     * 握手完成后立即向 sid 出站队列写入 Engine.IO ping（`2`）。
     *
     * 保证客户端第一条 long-poll GET 能在 pingTimeout 内收到数据，而非空等 poll_timeout。
     */
    public static function touchSession(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        SocketIOSessionManager::enqueueOutbound($sid, SocketIOPacket::ENGINE_PING);
    }

    /** 扫描连接表中 is_polling=1 的会话，逐个 enqueue ping */
    private static function sendPings(array $config): void
    {
        if (!TableManager::isExistTable(WebsocketConnectionManager::TABLE_CONNECTIONS)) {
            return;
        }

        foreach (TableManager::getTable(WebsocketConnectionManager::TABLE_CONNECTIONS) as $row) {
            if (empty($row['is_polling'])) {
                continue;
            }

            $sid = (string) ($row['sid'] ?? '');
            if ($sid === '') {
                continue;
            }

            if (!SocketIOSessionManager::hasSession($sid)) {
                continue;
            }

            SocketIOSessionManager::enqueueOutbound($sid, SocketIOPacket::ENGINE_PING);
        }
    }
}

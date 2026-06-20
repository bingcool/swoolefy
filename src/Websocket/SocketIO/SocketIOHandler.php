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

namespace Swoolefy\Websocket\SocketIO;

use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoolefy\Websocket\WebsocketConnectionManager;
use Swoolefy\Websocket\WebsocketHandler;
use Swoolefy\Websocket\WebsocketPacket;

/**
 * Socket.IO v4（Engine.IO v4 + WebSocket transport）服务端协议处理。
 *
 * ## 在框架中的位置
 *
 * `WebsocketServer` 在 `socketio.enable=true` 时：
 * - `onOpen`：识别 Socket.IO 握手 → `SocketIOHandler::onOpen`（发 Engine.IO open）
 * - `onMessage`：解析 Socket.IO 帧 → `SocketIOHandler::onMessage`
 * - 出站推送：连接 `is_socketio=1` 时由 `WebsocketConnectionManager::encodeEventPayload` 编码为 `42[...]`
 *
 * ## 与原生 WebSocket 的关系
 *
 * Socket.IO 业务事件最终**复用** `WebsocketHandler` + `Router/service.php` 分发，
 * 仅多一层 Socket.IO 包解析与 `event_routes` 映射（`chat.send` → `Service/Chat/Send`）。
 *
 * ## 握手与消息流（服务端视角）
 *
 * ```
 * 客户端 GET /socket.io/?EIO=4&transport=websocket&uid=...
 *   → isSocketIORequest()
 *   → onOpen：WebsocketConnectionManager::open(is_socketio=true)，push 0{"sid":...}
 * 客户端 → 40
 *   → onMessage：push 40{"sid":...}
 * 客户端 → 42["chat.send",{...}] 或 421[...]
 *   → dispatchEvent → WebsocketHandler → 可选 431[...] ack
 * 客户端 → 2 (ping)
 *   → push 3 (pong)
 * ```
 *
 * ## 配置（Config/socketio.php）
 *
 * - `enable`：是否启用本 Handler
 * - `ping_interval` / `ping_timeout` / `max_payload`：写入 open 包
 * - `event_routes`：事件名 → Router endpoint
 *
 * @see SocketIOPacket
 * @see SocketIOClient
 */
class SocketIOHandler
{
    /**
     * 判断 WebSocket 升级请求是否为 Socket.IO v4 + websocket transport。
     *
     * 条件：path 以 `/socket.io` 开头，且 query 含 `EIO=4`、`transport=websocket`。
     * 不处理 long-polling（transport=polling）。
     */
    public static function isSocketIORequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');

        return self::isSocketIOPath($path) && $transport === 'websocket' && $eio === '4';
    }

    /**
     * 判断 HTTP 请求 path 是否为 Socket.IO（用于非 WS 阶段的探测/拒绝）。
     */
    public static function isSocketIOHttpRequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');

        return self::isSocketIOPath($path);
    }

    /**
     * WebSocket 连接建立后发送 Engine.IO open，并完成连接表注册。
     *
     * - 生成 Engine.IO `sid` 存入连接表（与集群 conn 元数据无关，仅 Socket.IO 会话用）
     * - `user_id` 来自鉴权，供 pushToUser / 单聊
     * - open 包内 ping 参数来自 `config.socketio`，单位在 SocketIOPacket::open 中转毫秒
     *
     * 客户端收到 open 后应发送 `40` 完成 namespace connect。
     */
    public static function onOpen(Server $server, Request $request, array $config = [], string $userId = ''): void
    {
        $sid = self::generateSid();
        WebsocketConnectionManager::open($server, $request, [
            'user_id' => $userId,
            'sid' => $sid,
            'is_socketio' => true,
        ]);

        $pingInterval = (int) ($config['socketio']['ping_interval'] ?? 25);
        $pingTimeout = (int) ($config['socketio']['ping_timeout'] ?? 20);
        $maxPayload = (int) ($config['socketio']['max_payload'] ?? 1000000);

        $server->push((int) $request->fd, SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload));
    }

    /**
     * 处理一帧 Socket.IO 文本消息。
     *
     * 分支：
     * - ENGINE_PING → pong
     * - ENGINE_CLOSE / SOCKET_DISCONNECT → 关闭连接
     * - SOCKET_CONNECT → connect ack
     * - SOCKET_EVENT → 转 WebsocketHandler 分发
     *
     * @return bool push/disconnect 是否成功（Swoole 返回值）
     */
    public static function onMessage(Server $server, Frame $frame, array $config = []): bool
    {
        WebsocketConnectionManager::touch((int) $frame->fd);

        try {
            $packet = SocketIOPacket::parse((string) $frame->data);
        } catch (\Throwable $throwable) {
            return $server->push($frame->fd, SocketIOPacket::error($throwable->getMessage()));
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_PING) {
            return $server->push($frame->fd, SocketIOPacket::pong());
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_PONG) {
            return true;
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_CLOSE) {
            WebsocketConnectionManager::close((int) $frame->fd);

            return $server->disconnect($frame->fd, 1000, 'Socket.IO close');
        }

        if ($packet->engineType !== SocketIOPacket::ENGINE_MESSAGE) {
            return $server->push($frame->fd, SocketIOPacket::error('Unsupported Engine.IO packet'));
        }

        if ($packet->socketType === SocketIOPacket::SOCKET_CONNECT) {
            $connection = WebsocketConnectionManager::getConnection((int) $frame->fd);
            $sid = (string) ($connection['sid'] ?? self::generateSid());

            return $server->push($frame->fd, SocketIOPacket::connectAck($sid, $packet->namespace));
        }

        if ($packet->socketType === SocketIOPacket::SOCKET_DISCONNECT) {
            WebsocketConnectionManager::close((int) $frame->fd);

            return $server->disconnect($frame->fd, 1000, 'Socket.IO disconnect');
        }

        if ($packet->socketType !== SocketIOPacket::SOCKET_EVENT) {
            return $server->push($frame->fd, SocketIOPacket::error('Unsupported Socket.IO packet', -1, $packet->namespace));
        }

        return self::dispatchEvent($server, $frame, $packet, $config);
    }

    /**
     * 将 Socket.IO event 转为框架 WebsocketPacket 并走统一业务分发。
     *
     * 1. `event_routes` 或 `chat.send` → `chat/send` 解析 endpoint
     * 2. 构造 WebsocketPacket（params 含 `_socketio` 元数据）
     * 3. WebsocketHandler::handlePacket（中间件、ServiceDispatch、异常与原生 WS 一致）
     * 4. 若客户端带了 ack id：返回 `43{id}[{code,msg}]`（最小确认；业务仍可另 push event）
     *
     * @param array $config 含 socketio.event_routes 的 websocket 合并配置
     */
    private static function dispatchEvent(Server $server, Frame $frame, SocketIOPacket $packet, array $config): bool
    {
        $endpoint = self::resolveEndpoint($packet->event, $config);
        $params = $packet->data;
        $params['_socketio'] = [
            'event' => $packet->event,
            'namespace' => $packet->namespace,
            'ack_id' => $packet->id,
            'args' => $packet->args,
        ];

        $websocketPacket = new WebsocketPacket(
            (int) $frame->fd,
            (string) $frame->data,
            $endpoint,
            $params,
            WebsocketPacket::TYPE_EVENT,
            $packet->id,
            (int) $frame->opcode,
            (bool) $frame->finish,
            ['socketio' => true, 'namespace' => $packet->namespace, 'event' => $packet->event]
        );

        $handler = new WebsocketHandler();
        $ok = $handler->handlePacket($websocketPacket, false);

        if ($packet->id !== '') {
            $ack = $ok
                ? SocketIOPacket::ack($packet->id, [['code' => 0, 'msg' => 'ok']], $packet->namespace)
                : SocketIOPacket::ack($packet->id, [['code' => -1, 'msg' => 'dispatch failed']], $packet->namespace);

            return $server->push($frame->fd, $ack);
        }

        if (!$ok) {
            return $server->push($frame->fd, SocketIOPacket::error('dispatch failed', -1, $packet->namespace));
        }

        return true;
    }

    /**
     * 事件名 → Router endpoint。
     *
     * 优先 `Config/socketio.php` → `event_routes`；
     * 否则约定 `chat.send` → `chat/send`（与 Router/service.php 中路径一致）。
     */
    private static function resolveEndpoint(string $event, array $config): string
    {
        $routes = $config['socketio']['event_routes'] ?? [];
        if (is_array($routes) && isset($routes[$event])) {
            return trim((string) $routes[$event], '/');
        }

        return trim(str_replace('.', '/', $event), '/');
    }

    /** path 以 `/socket.io` 开头即视为 Socket.IO 路径 */
    private static function isSocketIOPath(string $path): bool
    {
        return str_starts_with($path, '/socket.io');
    }

    /** 生成 Engine.IO 会话 sid（16 字节 hex） */
    private static function generateSid(): string
    {
        return bin2hex(random_bytes(16));
    }
}

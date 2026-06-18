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

class SocketIOHandler
{
    public static function isSocketIORequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');

        // 生产默认只接收 Engine.IO v4 websocket transport，避免误处理 polling 请求。
        return self::isSocketIOPath($path) && $transport === 'websocket' && $eio === '4';
    }

    public static function isSocketIOHttpRequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        return self::isSocketIOPath($path);
    }

    public static function onOpen(Server $server, Request $request, array $config = [], string $userId = ''): void
    {
        // sid 是 Engine.IO 会话 ID，后续 namespace connect 和 ack 都依赖这个连接上下文。
        $sid = self::generateSid();
        WebsocketConnectionManager::open($server, $request, [
            'user_id' => $userId,
            'sid' => $sid,
            'is_socketio' => true,
        ]);

        $pingInterval = (int) ($config['socketio']['ping_interval'] ?? 25);
        $pingTimeout = (int) ($config['socketio']['ping_timeout'] ?? 20);
        $maxPayload = (int) ($config['socketio']['max_payload'] ?? 1000000);

        // Engine.IO v4 握手包，Socket.IO 客户端收到后会发送 40 完成 namespace connect。
        $server->push((int) $request->fd, SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload));
    }

    public static function onMessage(Server $server, Frame $frame, array $config = []): bool
    {
        WebsocketConnectionManager::touch((int) $frame->fd);

        try {
            $packet = SocketIOPacket::parse((string) $frame->data);
        } catch (\Throwable $throwable) {
            return $server->push($frame->fd, SocketIOPacket::error($throwable->getMessage()));
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_PING) {
            // Engine.IO 心跳层：收到 2 必须回复 3，否则官方客户端会主动断开。
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
            // Socket.IO namespace connect ack: 40{"sid":"..."}，客户端收到后才会触发 connect。
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

    private static function dispatchEvent(Server $server, Frame $frame, SocketIOPacket $packet, array $config): bool
    {
        // Socket.IO event 先映射成框架 endpoint，再复用 WebsocketHandler 的中间件和 ServiceDispatch。
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
            // 客户端传 id 表示需要 ack，这里返回最小确认包；业务响应仍可自行 push event。
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

    private static function resolveEndpoint(string $event, array $config): string
    {
        $routes = $config['socketio']['event_routes'] ?? [];
        if (is_array($routes) && isset($routes[$event])) {
            return trim((string) $routes[$event], '/');
        }

        // 默认约定：chat.send -> chat/send，允许直接在 Router/service.php 中配置该 endpoint。
        return trim(str_replace('.', '/', $event), '/');
    }

    private static function isSocketIOPath(string $path): bool
    {
        return str_starts_with($path, '/socket.io');
    }

    private static function generateSid(): string
    {
        return bin2hex(random_bytes(16));
    }
}

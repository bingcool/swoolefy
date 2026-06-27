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
 * Socket.IO v4（Engine.IO v4 + websocket / long-polling）服务端协议处理。
 *
 * ## Transport
 *
 * - **websocket**：`transport=websocket` WebSocket 升级（默认）
 * - **polling**：`transport=polling` HTTP GET/POST，见 {@see SocketIOPollingHandler}
 * - **upgrade**：polling 握手后 `transport=websocket&sid=...` 可升级到 WebSocket
 *
 * @see SocketIOPacket
 * @see SocketIOPollingHandler
 * @see SocketIOClient
 */
class SocketIOHandler
{
    /**
     * 判断 WebSocket 升级请求是否为 Socket.IO v4 + websocket transport。
     */
    public static function isSocketIORequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');

        return self::isSocketIOPath($path) && $transport === 'websocket' && $eio === '4';
    }

    public static function isSocketIOPollingRequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');
        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');

        return self::isSocketIOPath($path) && $transport === 'polling' && $eio === '4';
    }

    public static function isSocketIOHttpRequest(Request $request): bool
    {
        $path = (string) ($request->server['path_info'] ?? $request->server['request_uri'] ?? '');

        return self::isSocketIOPath($path);
    }

    public static function isPollingEnabled(array $config): bool
    {
        $socketio = $config['socketio'] ?? [];

        return !empty($socketio['allow_polling']);
    }

    public static function isWebSocketTransportEnabled(array $config): bool
    {
        $socketio = $config['socketio'] ?? [];
        $transports = $socketio['transports'] ?? ['websocket', 'polling'];

        return !is_array($transports) || in_array('websocket', $transports, true);
    }

    public static function pollTimeout(array $config): int
    {
        $socketio = $config['socketio'] ?? [];

        return max(1, (int) ($socketio['poll_timeout'] ?? 25));
    }

    /**
     * WebSocket 连接建立后发送 Engine.IO open，或从 polling 升级。
     */
    public static function onOpen(Server $server, Request $request, array $config = [], string $userId = ''): void
    {
        $upgradeSid = (string) ($request->get['sid'] ?? '');
        if ($upgradeSid !== '' && SocketIOSessionManager::hasSession($upgradeSid)) {
            self::upgradePollingToWebSocket($server, $request, $config, $upgradeSid, $userId);

            return;
        }

        $sid = self::generateSid();
        WebsocketConnectionManager::open($server, $request, [
            'user_id' => $userId,
            'sid' => $sid,
            'is_socketio' => true,
        ]);

        $server->push((int) $request->fd, self::buildOpenPacket($sid, $config));
    }

    /**
     * 处理一帧 Socket.IO 文本消息（WebSocket transport）。
     */
    public static function onMessage(Server $server, Frame $frame, array $config = []): bool
    {
        $outbound = self::handleInbound((int) $frame->fd, (string) $frame->data, $config, $server);
        $ok = true;
        foreach ($outbound as $packet) {
            $ok = $server->push($frame->fd, $packet) && $ok;
        }

        return $ok;
    }

    /**
     * 处理单条 Engine.IO 入站包，返回需回写的出站包列表。
     *
     * @return string[]
     */
    public static function handleInbound(int $fd, string $raw, array $config, ?Server $server = null): array
    {
        WebsocketConnectionManager::touch($fd);

        try {
            $packet = SocketIOPacket::parse($raw);
        } catch (\Throwable $throwable) {
            return [SocketIOPacket::error($throwable->getMessage())];
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_PING) {
            return [SocketIOPacket::pong()];
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_PONG) {
            return [];
        }

        if ($packet->engineType === SocketIOPacket::ENGINE_CLOSE) {
            self::closeConnection($fd, $server);

            return [];
        }

        if ($packet->engineType !== SocketIOPacket::ENGINE_MESSAGE) {
            return [SocketIOPacket::error('Unsupported Engine.IO packet')];
        }

        if ($packet->socketType === SocketIOPacket::SOCKET_CONNECT) {
            $connection = WebsocketConnectionManager::getConnection($fd);
            $sid = (string) ($connection['sid'] ?? self::generateSid());

            return [SocketIOPacket::connectAck($sid, $packet->namespace)];
        }

        if ($packet->socketType === SocketIOPacket::SOCKET_DISCONNECT) {
            self::closeConnection($fd, $server);

            return [];
        }

        if ($packet->socketType !== SocketIOPacket::SOCKET_EVENT) {
            return [SocketIOPacket::error('Unsupported Socket.IO packet', -1, $packet->namespace)];
        }

        return self::dispatchEvent($fd, $raw, $packet, $config, $server);
    }

    public static function generateSidPublic(): string
    {
        return self::generateSid();
    }

    private static function upgradePollingToWebSocket(
        Server $server,
        Request $request,
        array $config,
        string $sid,
        string $userId
    ): void {
        unset($config);
        $virtualFd = SocketIOSessionManager::getVirtualFd($sid);
        $connection = WebsocketConnectionManager::getConnection($virtualFd);
        if (!$connection) {
            $server->disconnect((int) $request->fd, 1008, 'invalid polling session');

            return;
        }

        $boundUserId = (string) ($connection['user_id'] ?? $userId);
        $groups = WebsocketConnectionManager::decodeGroupsPublic((string) ($connection['groups'] ?? ''));
        $pending = SocketIOSessionManager::drainOutbound($sid);

        WebsocketConnectionManager::closePollingVirtual($virtualFd);

        WebsocketConnectionManager::open($server, $request, [
            'user_id' => $boundUserId,
            'sid' => $sid,
            'is_socketio' => true,
        ]);

        $realFd = (int) $request->fd;
        WebsocketConnectionManager::restoreGroupsWithoutAuth($realFd, $groups);

        foreach ($pending as $packet) {
            $server->push($realFd, $packet);
        }
    }

    private static function buildOpenPacket(string $sid, array $config): string
    {
        $socketio = $config['socketio'] ?? [];
        $pingInterval = (int) ($socketio['ping_interval'] ?? 25);
        $pingTimeout = (int) ($socketio['ping_timeout'] ?? 20);
        $maxPayload = (int) ($socketio['max_payload'] ?? 1000000);

        return SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload, []);
    }

    /**
     * @return string[]
     */
    private static function dispatchEvent(
        int $fd,
        string $raw,
        SocketIOPacket $packet,
        array $config,
        ?Server $server
    ): array {
        $endpoint = self::resolveEndpoint($packet->event, $config);
        $params = $packet->data;
        $params['_socketio'] = [
            'event' => $packet->event,
            'namespace' => $packet->namespace,
            'ack_id' => $packet->id,
            'args' => $packet->args,
        ];

        $websocketPacket = new WebsocketPacket(
            $fd,
            $raw,
            $endpoint,
            $params,
            WebsocketPacket::TYPE_EVENT,
            $packet->id,
            WEBSOCKET_OPCODE_TEXT,
            true,
            ['socketio' => true, 'namespace' => $packet->namespace, 'event' => $packet->event]
        );

        $handler = new WebsocketHandler();
        $ok = $handler->handlePacket($websocketPacket, false);
        $outbound = [];

        if ($packet->id !== '') {
            $outbound[] = $ok
                ? SocketIOPacket::ack($packet->id, [['code' => 0, 'msg' => 'ok']], $packet->namespace)
                : SocketIOPacket::ack($packet->id, [['code' => -1, 'msg' => 'dispatch failed']], $packet->namespace);
        } elseif (!$ok) {
            $outbound[] = SocketIOPacket::error('dispatch failed', -1, $packet->namespace);
        }

        return $outbound;
    }

    private static function closeConnection(int $fd, ?Server $server): void
    {
        if (SocketIOSessionManager::isVirtualFd($fd)) {
            WebsocketConnectionManager::closePollingVirtual($fd);

            return;
        }

        WebsocketConnectionManager::close($fd);
        if ($server !== null && $server->isEstablished($fd)) {
            $server->disconnect($fd, 1000, 'Socket.IO close');
        }
    }

    private static function resolveEndpoint(string $event, array $config): string
    {
        $routes = $config['socketio']['event_routes'] ?? [];
        if (is_array($routes) && isset($routes[$event])) {
            return trim((string) $routes[$event], '/');
        }

        return trim(str_replace('.', '/', $event), '/');
    }

    private static function isSocketIOPath(string $path): bool
    {
        $path = explode('?', $path, 2)[0];

        return str_starts_with($path, '/socket.io');
    }

    private static function generateSid(): string
    {
        return bin2hex(random_bytes(16));
    }
}

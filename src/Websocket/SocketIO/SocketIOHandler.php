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
 * ## 职责边界
 *
 * - **Engine 层**：open / ping-pong / close（`0`~`3`）
 * - **Socket 层**：namespace connect/disconnect / event / ack（`40`~`44`、`45`/`46` 二进制）
 * - **业务层**：统一转 {@see WebsocketHandler} + `Config/socketio.php` 路由
 *
 * ## 关键流程
 *
 * ```
 * onOpen → 0{sid}（或 polling→websocket upgrade 复用 sid）
 * onMessage TEXT → parse → [BinaryAssembler 等附件] → handleParsedPacket
 * onMessage BINARY → BinaryAssembler.feedBinary → handleParsedPacket
 * handleParsedPacket:
 *   40 → NamespaceRegistry.connectNamespace
 *   41 → disconnectNamespace（仅最后一个 ns 才关 Engine）
 *   42/45 → dispatchEvent → WebsocketHandler
 * ```
 *
 * @see SocketIOPacket
 * @see SocketIOPollingHandler
 * @see SocketIONamespaceRegistry
 * @see SocketIOBinaryAssembler
 */
class SocketIOHandler
{
    /** WebSocket 升级：EIO=4 + transport=websocket */
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
     * WebSocket 连接建立：新连接发 open；带 sid 则为 polling→websocket 升级。
     */
    public static function onOpen(Server $server, Request $request, array $config = [], string $userId = ''): void
    {
        $upgradeSid = (string) ($request->get['sid'] ?? '');
        // upgrade 时不重复发 open，仅迁移虚拟 fd 上的会话状态
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
     * WebSocket 入站：TEXT 为 Engine.IO 文本包；BINARY 为二进制附件后续帧。
     *
     * @param bool $alreadyTouched 上层（如 dispatchMessageFrame）是否已 touch
     */
    public static function onMessage(Server $server, Frame $frame, array $config = [], bool $alreadyTouched = false): bool
    {
        $fd = (int) $frame->fd;
        if ((int) $frame->opcode === WEBSOCKET_OPCODE_BINARY) {
            // 二进制 event 的第二帧起：与上一 TEXT 包中的 attachmentCount 配对
            try {
                $packet = SocketIOBinaryAssembler::feedBinary($fd, (string) $frame->data);
            } catch (\Throwable $throwable) {
                // 超限/老化清理后回协议错误，避免未捕获异常打挂 Worker
                SocketIOBinaryAssembler::clear($fd);
                return self::pushOutbound($server, $fd, [SocketIOPacket::error($throwable->getMessage())]);
            }
            if ($packet === null) {
                return true;
            }
            $outbound = self::handleParsedPacket($fd, $packet, $config, $server);
        } else {
            $outbound = self::handleInbound($fd, (string) $frame->data, $config, $server, $alreadyTouched);
        }

        return self::pushOutbound($server, $fd, $outbound);
    }

    /**
     * @param bool $alreadyTouched 上层是否已刷新活跃时间
     * @return string[]
     */
    public static function handleInbound(
        int $fd,
        string $raw,
        array $config,
        ?Server $server = null,
        bool $alreadyTouched = false,
    ): array {
        if (!$alreadyTouched) {
            WebsocketConnectionManager::touch($fd);
        }

        try {
            $packet = SocketIOPacket::parse($raw);
        } catch (\Throwable $throwable) {
            return [SocketIOPacket::error($throwable->getMessage())];
        }

        if ($packet->engineType !== SocketIOPacket::ENGINE_MESSAGE) {
            return self::handleParsedPacket($fd, $packet, $config, $server);
        }

        // ENGINE_MESSAGE 且带 `-N-` 后缀时，需等待 BINARY 帧收齐后再 dispatch
        try {
            $ready = SocketIOBinaryAssembler::feedText($fd, $packet);
        } catch (\Throwable $throwable) {
            SocketIOBinaryAssembler::clear($fd);

            return [SocketIOPacket::error($throwable->getMessage())];
        }
        if ($ready === null) {
            return [];
        }

        return self::handleParsedPacket($fd, $ready, $config, $server);
    }

    /**
     * polling POST：解析 `\x1e` 分隔的文本包与 `b<base64>` 附件。
     *
     * @return string[]
     */
    public static function handleInboundPollingBatch(int $fd, string $body, array $config): array
    {
        $outbound = [];
        $chunks = SocketIOPacket::decodeBatch($body);
        $index = 0;
        while ($index < count($chunks)) {
            $chunk = $chunks[$index];
            if (SocketIOBinaryData::decodePollingAttachment($chunk) !== null) {
                $index++;
                continue;
            }

            try {
                $packet = SocketIOPacket::parse($chunk);
            } catch (\Throwable $throwable) {
                $outbound[] = SocketIOPacket::error($throwable->getMessage());
                $index++;
                continue;
            }

            if ($packet->attachmentCount > 0) {
                $attachments = [];
                for ($j = 0; $j < $packet->attachmentCount; $j++) {
                    $nextIndex = $index + 1 + $j;
                    if ($nextIndex >= count($chunks)) {
                        break;
                    }
                    $bytes = SocketIOBinaryData::decodePollingAttachment($chunks[$nextIndex]);
                    if ($bytes === null) {
                        break;
                    }
                    $attachments[] = $bytes;
                }
                $index += 1 + count($attachments);
                $packet = SocketIOBinaryAssembler::finalizeFromPolling($packet, $attachments);
            } else {
                $index++;
            }

            $outbound = array_merge($outbound, self::handleParsedPacket($fd, $packet, $config, null));
        }

        return $outbound;
    }

    public static function generateSidPublic(): string
    {
        return self::generateSid();
    }

    /**
     * Socket.IO 包路由核心（namespace / 二进制已在入口处理完毕）。
     *
     * @return string[] 需回写客户端的 Engine.IO 包列表
     */
    private static function handleParsedPacket(
        int $fd,
        SocketIOPacket $packet,
        array $config,
        ?Server $server
    ): array {
        if ($packet->engineType === SocketIOPacket::ENGINE_PING) {
            $payload = (string) ($packet->data['engine_payload'] ?? '');

            return [SocketIOPacket::pong($payload)];
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
            // 40/40/ns,：注册 namespace，同一 Engine 连接可多次 connect 不同 ns
            $error = SocketIONamespaceRegistry::connectNamespace($fd, $packet->namespace, $config);
            if ($error !== null) {
                return [SocketIOPacket::error($error, -1, $packet->namespace)];
            }
            $connection = WebsocketConnectionManager::getConnection($fd);
            $sid = (string) ($connection['sid'] ?? self::generateSid());

            return [SocketIOPacket::connectAck($sid, $packet->namespace)];
        }

        if ($packet->socketType === SocketIOPacket::SOCKET_DISCONNECT) {
            // 41：仅断开指定 namespace；全部 ns 断开后才关闭 Engine 连接
            if (SocketIONamespaceRegistry::disconnectNamespace($fd, $packet->namespace)) {
                self::closeConnection($fd, $server);
            }

            return [];
        }

        if ($packet->socketType !== SocketIOPacket::SOCKET_EVENT) {
            return [SocketIOPacket::error('Unsupported Socket.IO packet', -1, $packet->namespace)];
        }

        // 未 connect 的 namespace 不允许 emit
        if (!SocketIONamespaceRegistry::isConnected($fd, $packet->namespace)) {
            return [SocketIOPacket::error('namespace not connected', -1, $packet->namespace)];
        }

        return self::dispatchEvent($fd, $packet, $config);
    }

    /**
     * polling→websocket 升级：虚拟 fd 上的 user/group/namespace/出站队列 迁移到真实 fd。
     *
     * 注意：升级后不再发送 Engine open（客户端 polling 阶段已收到）。
     */
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
        $namespaces = (string) ($connection['socketio_namespaces'] ?? '');
        $pending = SocketIOSessionManager::drainOutbound($sid);

        WebsocketConnectionManager::closePollingVirtual($virtualFd);

        WebsocketConnectionManager::open($server, $request, [
            'user_id' => $boundUserId,
            'sid' => $sid,
            'is_socketio' => true,
        ]);

        $realFd = (int) $request->fd;
        WebsocketConnectionManager::restoreGroupsWithoutAuth($realFd, $groups);
        if ($namespaces !== '') {
            WebsocketConnectionManager::updateConnection($realFd, array_merge(
                WebsocketConnectionManager::getConnection($realFd) ?? [],
                ['socketio_namespaces' => $namespaces]
            ));
        }

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
        // open 包中的 upgrades 告知客户端可升级到 WebSocket
        $upgrades = self::isPollingEnabled($config) && self::isWebSocketTransportEnabled($config) ? ['websocket'] : [];

        return SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload, $upgrades);
    }

    /**
     * Socket.IO event → 框架 WebsocketPacket；路由按 namespace 解析 endpoint。
     *
     * params 中带 `_socketio` 元数据，供业务层区分 ns / ack / 原始 args。
     */
    private static function dispatchEvent(int $fd, SocketIOPacket $packet, array $config): array
    {
        $endpoint = SocketIONamespaceRegistry::resolveEndpoint($packet->event, $packet->namespace, $config);
        $params = $packet->data;
        $params['_socketio'] = [
            'event' => $packet->event,
            'namespace' => $packet->namespace,
            'ack_id' => $packet->id,
            'args' => $packet->args,
        ];

        $raw = SocketIOPacket::event($packet->event, $packet->args, $packet->namespace);
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
        // 入站路径已由 dispatchMessageFrame / handleInbound 完成 touch
        $ok = $handler->handlePacket($websocketPacket, false, false);
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

    /** @param string[] $outbound */
    private static function pushOutbound(Server $server, int $fd, array $outbound): bool
    {
        $ok = true;
        foreach ($outbound as $packet) {
            $ok = $server->push($fd, $packet) && $ok;
        }

        return $ok;
    }

    private static function closeConnection(int $fd, ?Server $server): void
    {
        SocketIOBinaryAssembler::clear($fd);

        if (SocketIOSessionManager::isVirtualFd($fd)) {
            WebsocketConnectionManager::closePollingVirtual($fd);

            return;
        }

        WebsocketConnectionManager::close($fd);
        if ($server !== null && $server->isEstablished($fd)) {
            $server->disconnect($fd, 1000, 'Socket.IO close');
        }
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

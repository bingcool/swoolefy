<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingEngineHeartbeat;
use Swoolefy\Websocket\WebsocketAuthenticator;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * Socket.IO HTTP long-polling transport（Engine.IO v4）。
 *
 * ## HTTP 路由
 *
 * | 方法 | Query | 行为 |
 * |------|-------|------|
 * | GET  | 无 sid | 握手 → `0{sid,...,"upgrades":["websocket"]}` |
 * | GET  | sid=… | long-poll 取服务端包（`\x1e` 分隔） |
 * | POST | sid=… | 客户端上行（`40` connect / `2` ping / `42` event）→ 同步响应 |
 * | OPTIONS | — | CORS 预检 |
 *
 * ## 与 WebSocket 的差异
 *
 * - 无持久 fd：用虚拟 fd + sid，push 写入 {@see SocketIOSessionManager} 出站队列
 * - **心跳**：polling 须服务端主动发 `2`（见 {@see SocketIOPollingEngineHeartbeat}）
 * - **long-poll**：无出站数据时立即空响应（禁止 BRPOP 阻塞），否则 engine.io 无法 POST `40` connect
 *
 * ## 配置
 *
 * - `accept_http=true`（Protocol/conf.php）
 * - `allow_polling=true`（Config/socketio.php）
 * - 多 Worker：`polling.shared_store=auto`（sid 进 Table，出站进 Redis）
 *
 * @see SocketIOPollingEngineHeartbeat  服务端 ping
 * @see SocketIOSessionManager          sid / 出站队列
 * @see SocketIOHandler                 POST 包解析与 namespace 路由
 */
class SocketIOPollingHandler
{
    public static function handleHttp(Request $request, Response $response, array $config): void
    {
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));
        if ($method === 'OPTIONS') {
            self::sendCorsPreflight($request, $response);

            return;
        }

        if (empty($config['socketio']['enable']) || !SocketIOHandler::isPollingEnabled($config)) {
            self::reject($request, $response, 400, 'Socket.IO polling is disabled');

            return;
        }

        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');
        if ($transport !== 'polling' || $eio !== '4') {
            self::reject($request, $response, 400, 'Invalid Engine.IO polling request');

            return;
        }

        $sid = (string) ($request->get['sid'] ?? '');
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));

        // 无 sid 的 GET：Engine 握手，分配 sid + 虚拟 fd
        if ($sid === '' && $method === 'GET') {
            self::handleHandshake($request, $response, $config);

            return;
        }

        if ($sid !== '' && $method === 'GET') {
            self::handlePoll($request, $response, $config, $sid);

            return;
        }

        if ($sid !== '' && $method === 'POST') {
            self::handlePost($request, $response, $config, $sid);

            return;
        }

        self::reject($request, $response, 400, 'Invalid Socket.IO polling request');
    }

    /** 首次 GET：鉴权 → 虚拟 fd 注册 → 返回 Engine open 包 → 预 enqueue 首包 ping */
    private static function handleHandshake(Request $request, Response $response, array $config): void
    {
        $auth = WebsocketAuthenticator::authenticate($request, $config);
        if (!$auth['ok']) {
            self::reject($request, $response, 401, (string) $auth['reason']);

            return;
        }

        $sid = SocketIOHandler::generateSidPublic();
        $virtualFd = SocketIOSessionManager::allocateVirtualFd();
        SocketIOSessionManager::bindSid($sid, $virtualFd);

        WebsocketConnectionManager::openPolling($request, [
            'user_id' => (string) $auth['user_id'],
            'sid' => $sid,
            'virtual_fd' => $virtualFd,
        ]);

        $socketio = $config['socketio'] ?? [];
        $pingInterval = (int) ($socketio['ping_interval'] ?? 25);
        $pingTimeout = (int) ($socketio['ping_timeout'] ?? 20);
        $maxPayload = (int) ($socketio['max_payload'] ?? 1000000);
        $upgrades = SocketIOHandler::isWebSocketTransportEnabled($config) ? ['websocket'] : [];

        // open 包仅含 Engine 参数；Socket.IO `40` connect ack 由客户端 POST `40` 后同步返回。
        // 不在握手后立即 enqueue ping：首包 GET 若返回 `2`，会拖住 engine.io flush POST `40`。
        self::sendPollingResponse(
            $request,
            $response,
            SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload, $upgrades)
        );
    }

    /**
     * long-poll GET：drain 出站队列并立即 end；无数据返回空 body。
     */
    private static function handlePoll(Request $request, Response $response, array $config, string $sid): void
    {
        if (!SocketIOSessionManager::hasSession($sid)) {
            self::reject($request, $response, 400, 'Unknown session id');

            return;
        }

        $virtualFd = SocketIOSessionManager::getVirtualFd($sid);
        WebsocketConnectionManager::touch($virtualFd);
        SocketIOSessionManager::touchSession($sid);

        $timeout = SocketIOHandler::pollTimeout($config);
        $packets = SocketIOSessionManager::waitOutbound($sid, $timeout);
        self::sendPollingResponse($request, $response, SocketIOPacket::encodeBatch($packets));
    }

    /**
     * POST：客户端上行 Engine.IO 包（`\x1e` 批处理）。
     *
     * 典型 body：`40`（namespace connect）、`3`（pong）、`42["evt",{}]`（event）。
     * 响应体可含 connect ack / pong / 业务 ack，与 long-poll GET 出站相互独立。
     */
    private static function handlePost(Request $request, Response $response, array $config, string $sid): void
    {
        if (!SocketIOSessionManager::hasSession($sid)) {
            self::reject($request, $response, 400, 'Unknown session id');

            return;
        }

        $virtualFd = SocketIOSessionManager::getVirtualFd($sid);
        WebsocketConnectionManager::touch($virtualFd);
        SocketIOSessionManager::touchSession($sid);

        $outbound = [];
        foreach (SocketIOHandler::handleInboundPollingBatch($virtualFd, (string) $request->rawContent(), $config) as $packet) {
            $outbound[] = $packet;
        }

        // connect ack 同时写入出站队列：部分客户端从 GET 读 ack，POST 响应单独丢失时仍可 connect
        foreach ($outbound as $packet) {
            if ($packet !== '' && $packet[0] === SocketIOPacket::ENGINE_MESSAGE && isset($packet[1]) && $packet[1] === SocketIOPacket::SOCKET_CONNECT) {
                SocketIOSessionManager::enqueueOutbound($sid, $packet);
                SocketIOPollingEngineHeartbeat::touchSession($sid);
            }
        }

        self::sendPollingResponse($request, $response, SocketIOPacket::encodeBatch($outbound));
    }

    private static function sendPollingResponse(Request $request, Response $response, string $body): void
    {
        self::applyCorsHeaders($request, $response);
        $response->header('Content-Type', 'text/plain; charset=UTF-8');
        $response->status(200);
        $response->end($body);
    }

    private static function reject(Request $request, Response $response, int $status, string $message): void
    {
        self::applyCorsHeaders($request, $response);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        $response->status($status);
        $response->end(json_encode(['code' => -1, 'msg' => $message], JSON_UNESCAPED_UNICODE));
    }

    /** 浏览器跨域 POST 前的 OPTIONS 预检 */
    private static function sendCorsPreflight(Request $request, Response $response): void
    {
        self::applyCorsHeaders($request, $response);
        $response->header('Access-Control-Max-Age', '86400');
        $response->status(204);
        $response->end('');
    }

    /**
     * CORS：有 Origin 时回显具体域名；禁止 `*` 与 `Allow-Credentials: true` 同用（浏览器会拒 xhr）。
     */
    private static function applyCorsHeaders(Request $request, Response $response): void
    {
        $origin = trim((string) ($request->header['origin'] ?? ''));
        if ($origin !== '') {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
        } else {
            $response->header('Access-Control-Allow-Origin', '*');
        }

        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type');
    }
}

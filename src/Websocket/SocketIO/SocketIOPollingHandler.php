<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
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
 * | POST | sid=… | 客户端上行（`40` connect / `2` ping / `42` event）→ `ok` |
 * | OPTIONS | — | CORS 预检 |
 *
 * ## 与 WebSocket 的差异
 *
 * - 无持久 fd：用虚拟 fd + sid，push 写入 {@see SocketIOSessionManager} 出站队列
 * - **心跳**：polling 须服务端主动发 `2`（见 {@see SocketIOPollingEngineHeartbeat}）
 * - **long-poll**：单 sid 单 waiter + 短 BRPOP（默认 2s）；无数据或未获锁返回 noop `6`
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

        try {
            // 先 openPolling 写入含 user_id 的连接元数据，再 bindSid 写 Redis Hash，避免 sid 元数据缺 user_id
            WebsocketConnectionManager::openPolling($request, [
                'user_id' => (string) $auth['user_id'],
                'sid' => $sid,
                'virtual_fd' => $virtualFd,
            ]);
            $connId = (string) ((WebsocketConnectionManager::getConnection($virtualFd)['conn_id'] ?? ''));
            SocketIOSessionManager::bindSid($sid, $virtualFd, $connId);
        } catch (\Throwable $throwable) {
            // 任一步失败回滚本地连接、sid 与用户索引，避免孤儿 sid
            try {
                WebsocketConnectionManager::closePollingVirtual($virtualFd);
            } catch (\Throwable $ignored) {
            }
            try {
                SocketIOSessionManager::destroySession($sid);
            } catch (\Throwable $ignored) {
            }
            self::reject($request, $response, 500, 'polling handshake failed');

            return;
        }

        $socketio = $config['socketio'] ?? [];
        $pingInterval = (int) ($socketio['ping_interval'] ?? 25);
        $pingTimeout = (int) ($socketio['ping_timeout'] ?? 20);
        $maxPayload = (int) ($socketio['max_payload'] ?? 1000000);
        $upgrades = SocketIOHandler::isWebSocketTransportEnabled($config) ? ['websocket'] : [];

        // open 包仅含 Engine 参数；Socket.IO `40` connect ack 由客户端 POST `40` 后入队给 GET。
        // 不在握手后立即 enqueue ping：首包 GET 若先返回 `2`，Socket.IO connect 尚未完成会 parser error。
        self::sendPollingResponse(
            $request,
            $response,
            SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload, $upgrades)
        );
    }

    /**
     * long-poll GET：drain → 短阻塞 waitOutbound → end。
     */
    private static function handlePoll(Request $request, Response $response, array $config, string $sid): void
    {
        $virtualFd = SocketIOSessionManager::resolveSession($sid);
        if ($virtualFd <= 0) {
            self::rejectUnknownSession($request, $response);

            return;
        }

        WebsocketConnectionManager::touch($virtualFd);
        SocketIOSessionManager::touchSession($sid);

        $timeout = SocketIOHandler::pollTimeout($config);
        $packets = SocketIOSessionManager::waitOutbound($sid, $timeout);
        self::sendPollingResponse($request, $response, SocketIOPacket::encodePollingPayload($packets));
    }

    /**
     * POST：客户端上行 Engine.IO 包（`\x1e` 批处理）。
     *
     * 典型 body：`40`（namespace connect）、`3`（pong）、`42["evt",{}]`（event）。
     *
     * Engine.IO polling 的 POST 响应必须是 `ok`；服务端下行包统一入队，由 GET long-poll 取走。
     * namespace connect 成功后只入队 `40{sid}`；心跳由周期 ping 负责，避免 ping 抢在 connect ack 前送达。
     */
    private static function handlePost(Request $request, Response $response, array $config, string $sid): void
    {
        $virtualFd = SocketIOSessionManager::resolveSession($sid);
        if ($virtualFd <= 0) {
            self::rejectUnknownSession($request, $response);

            return;
        }

        WebsocketConnectionManager::touch($virtualFd);
        SocketIOSessionManager::touchSession($sid);

        $outbound = [];
        foreach (SocketIOHandler::handleInboundPollingBatch($virtualFd, (string) $request->rawContent(), $config) as $packet) {
            $outbound[] = $packet;
        }

        foreach ($outbound as $packet) {
            SocketIOSessionManager::enqueueOutbound($sid, $packet);
        }

        self::sendPollingResponse($request, $response, 'ok');
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

    /**
     * sid 无效时返回 Engine.IO 兼容 JSON（code/message），避免 engine.io-client parser error。
     */
    private static function rejectUnknownSession(Request $request, Response $response): void
    {
        self::applyCorsHeaders($request, $response);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        $response->status(400);
        $response->end(json_encode([
            'code' => 1,
            'message' => 'Session ID unknown',
        ], JSON_UNESCAPED_UNICODE));
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

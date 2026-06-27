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
 * | 方法 | Query | 行为 |
 * |------|-------|------|
 * | GET  | 无 sid | 握手 → `0{sid,...,"upgrades":["websocket"]}` |
 * | GET  | sid=… | long-poll 取服务端包（`\x1e` 分隔） |
 * | POST | sid=… | 客户端发包 → 可选同步响应包 |
 *
 * 需 `accept_http=true`；多 Worker 需会话粘性。
 */
class SocketIOPollingHandler
{
    public static function handleHttp(Request $request, Response $response, array $config): void
    {
        if (empty($config['socketio']['enable']) || !SocketIOHandler::isPollingEnabled($config)) {
            self::reject($response, 400, 'Socket.IO polling is disabled');

            return;
        }

        $transport = (string) ($request->get['transport'] ?? '');
        $eio = (string) ($request->get['EIO'] ?? '');
        if ($transport !== 'polling' || $eio !== '4') {
            self::reject($response, 400, 'Invalid Engine.IO polling request');

            return;
        }

        $sid = (string) ($request->get['sid'] ?? '');
        $method = strtoupper((string) ($request->server['request_method'] ?? 'GET'));

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

        self::reject($response, 400, 'Invalid Socket.IO polling request');
    }

    private static function handleHandshake(Request $request, Response $response, array $config): void
    {
        $auth = WebsocketAuthenticator::authenticate($request, $config);
        if (!$auth['ok']) {
            self::reject($response, 401, (string) $auth['reason']);

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

        self::sendPollingResponse(
            $response,
            SocketIOPacket::open($sid, $pingInterval, $pingTimeout, $maxPayload, $upgrades)
        );
    }

    private static function handlePoll(Request $request, Response $response, array $config, string $sid): void
    {
        if (!SocketIOSessionManager::hasSession($sid)) {
            self::reject($response, 400, 'Unknown session id');

            return;
        }

        $virtualFd = SocketIOSessionManager::getVirtualFd($sid);
        WebsocketConnectionManager::touch($virtualFd);

        $timeout = SocketIOHandler::pollTimeout($config);
        $packets = SocketIOSessionManager::waitOutbound($sid, $timeout);
        self::sendPollingResponse($response, SocketIOPacket::encodeBatch($packets));
    }

    private static function handlePost(Request $request, Response $response, array $config, string $sid): void
    {
        if (!SocketIOSessionManager::hasSession($sid)) {
            self::reject($response, 400, 'Unknown session id');

            return;
        }

        $virtualFd = SocketIOSessionManager::getVirtualFd($sid);
        WebsocketConnectionManager::touch($virtualFd);

        $outbound = [];
        foreach (SocketIOPacket::decodeBatch((string) $request->rawContent()) as $raw) {
            $outbound = array_merge(
                $outbound,
                SocketIOHandler::handleInbound($virtualFd, $raw, $config, null)
            );
        }

        self::sendPollingResponse($response, SocketIOPacket::encodeBatch($outbound));
    }

    private static function sendPollingResponse(Response $response, string $body): void
    {
        $response->header('Content-Type', 'text/plain; charset=UTF-8');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->status(200);
        $response->end($body);
    }

    private static function reject(Response $response, int $status, string $message): void
    {
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->status($status);
        $response->end(json_encode(['code' => -1, 'msg' => $message], JSON_UNESCAPED_UNICODE));
    }
}

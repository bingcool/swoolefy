<?php
/**
 * WebSocket 冒烟测试（需先启动示例应用 WebsocketService）。
 *
 * 覆盖：原生 WS ping/report、Socket.IO join/send、单聊 pushToUser。
 *
 * Run after server started:
 *   SWOOLEFY_CLI_ENV=dev php src/Websocket/Tests/WebsocketSmokeTest.php
 */

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Swoolefy\Websocket\SocketIO\SocketIOClient;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

const WS_HOST = '127.0.0.1';
const WS_PORT = 9508;

/** 断言条件为真，否则抛出 RuntimeException */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** 接收一帧 WebSocket 文本并解析为 JSON 数组 */
function recvJson(Client $client, string $case): array
{
    $frame = $client->recv();
    assertTrue($frame instanceof Frame, "{$case}: expected websocket frame");

    $payload = json_decode((string) $frame->data, true);
    assertTrue(is_array($payload), "{$case}: expected json response, got {$frame->data}");

    return $payload;
}

/** 原生 WebSocket：ping/pong、业务 request、非法 JSON 错误响应 */
function testRawWebsocket(): void
{
    $client = new Client(WS_HOST, WS_PORT);
    $client->set(['timeout' => 3]);
    assertTrue($client->upgrade('/?uid=raw-user'), 'raw websocket: upgrade failed');

    $client->push(json_encode([
        'type' => 'ping',
        'event' => 'Service/Demo/Ping',
        'request_id' => 'raw-ping-1',
        'data' => [],
    ], JSON_UNESCAPED_UNICODE));
    $pong = recvJson($client, 'raw websocket ping');
    assertTrue(($pong['type'] ?? '') === 'pong', 'raw websocket ping: expected pong response');
    assertTrue(($pong['request_id'] ?? '') === 'raw-ping-1', 'raw websocket ping: request_id mismatch');

    $client->push(json_encode([
        'type' => 'request',
        'event' => 'Service/Demo/ReportMsg',
        'request_id' => 'raw-report-1',
        'data' => ['msg' => 'hello raw websocket'],
    ], JSON_UNESCAPED_UNICODE));
    $report = recvJson($client, 'raw websocket report');
    assertTrue(($report['code'] ?? -1) === 0, 'raw websocket report: expected code=0');
    assertTrue(($report['data']['echo'] ?? '') === 'hello raw websocket', 'raw websocket report: echo mismatch');

    $client->push('Service/Demo/ReportMsg::{bad-json');
    $error = recvJson($client, 'raw websocket invalid json');
    assertTrue(($error['type'] ?? '') === 'error', 'raw websocket invalid json: expected error');

    $client->close();
    echo "[OK] raw websocket\n";
}

/** Socket.IO：握手、group.join、chat.send 带 ack */
function testSocketIO(): void
{
    $client = new SocketIOClient(WS_HOST, WS_PORT, false, 3);
    $client->connect(['uid' => 'socketio-user']);
    assertTrue($client->getSid() !== '', 'socket.io: missing sid');

    $joinAck = $client->emitWithAck('group.join', [['group' => 'public']], 3);
    assertTrue(($joinAck[0]['code'] ?? -1) === 0, 'socket.io group.join: expected ack code=0');

    $sendAck = $client->emitWithAck('chat.send', [[
        'group' => 'public',
        'message' => 'hello socket.io',
    ]], 3);
    assertTrue(($sendAck[0]['code'] ?? -1) === 0, 'socket.io chat.send: expected ack code=0');

    $client->close();
    echo "[OK] socket.io websocket transport\n";
}

/** 单聊：A 向 B pushToUser，双方握手带 uid，验证 ack 成功 */
function testPrivateChat(): void
{
    $receiver = new SocketIOClient(WS_HOST, WS_PORT, false, 3);
    $receiver->connect(['uid' => 'smoke-user-b']);

    $sender = new SocketIOClient(WS_HOST, WS_PORT, false, 3);
    $sender->connect(['uid' => 'smoke-user-a']);

    $ack = $sender->emitWithAck('chat.private', [[
        'to_user_id' => 'smoke-user-b',
        'message' => 'hello private',
    ]], 3);
    assertTrue(($ack[0]['code'] ?? -1) === 0, 'socket.io chat.private: expected ack code=0');

    $sender->close();
    $receiver->close();
    echo "[OK] socket.io private chat\n";
}

\Swoole\Coroutine\run(function () {
    testRawWebsocket();
    testSocketIO();
    testPrivateChat();
});

echo "All websocket smoke tests passed.\n";

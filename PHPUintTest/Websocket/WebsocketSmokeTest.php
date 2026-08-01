<?php

declare(strict_types=1);

namespace PHPUintTest\Websocket;

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use PHPUintTest\Websocket\Support\SmokeTestSupport;
use Swoolefy\Websocket\SocketIO\SocketIOClient;

/**
 * WebSocket 冒烟测试（需先启动示例应用 WebsocketService）。
 *
 * 覆盖：原生 WS ping/report、Socket.IO join/send、单聊 pushToUser。
 *
 * 环境变量（可选）：
 *   WS_HOST=127.0.0.1  WS_PORT=9508  WORKER_PORT=9508
 *   WS_SMOKE_SKIP_IF_DOWN=1  — 服务未启动时跳过而非 fatal
 */
#[\PHPUnit\Framework\Attributes\Group('smoke')]
final class WebsocketSmokeTest extends WebsocketSmokeTestCase
{
    /** @return array<string, mixed> */
    private function recvJson(Client $client, string $case): array
    {
        $frame = $client->recv();
        $this->assertInstanceOf(Frame::class, $frame, "{$case}: expected websocket frame");

        $payload = json_decode((string) $frame->data, true);
        $this->assertIsArray($payload, "{$case}: expected json response, got {$frame->data}");

        return $payload;
    }

    public function testRawWebsocket(): void
    {
        $this->runInCoroutine(function (): void {
            $client = new Client(self::$wsHost, self::$wsPort);
            $client->set(['timeout' => 5]);
            SmokeTestSupport::upgrade($client, '/?uid=raw-user', 'raw websocket');

            $client->push(json_encode([
                'type' => 'ping',
                'event' => 'Service/Demo/Ping',
                'request_id' => 'raw-ping-1',
                'data' => [],
            ], JSON_UNESCAPED_UNICODE));
            $pong = $this->recvJson($client, 'raw websocket ping');
            $this->assertSame('pong', $pong['type'] ?? '');
            $this->assertSame('raw-ping-1', $pong['request_id'] ?? '');

            $client->push(json_encode([
                'type' => 'request',
                'event' => 'Service/Demo/ReportMsg',
                'request_id' => 'raw-report-1',
                'data' => ['msg' => 'hello raw websocket'],
            ], JSON_UNESCAPED_UNICODE));
            $report = $this->recvJson($client, 'raw websocket report');
            $this->assertSame(0, $report['code'] ?? -1);
            $this->assertSame('hello raw websocket', $report['data']['echo'] ?? '');

            $client->push('Service/Demo/ReportMsg::{bad-json');
            $error = $this->recvJson($client, 'raw websocket invalid json');
            $this->assertSame('error', $error['type'] ?? '');

            $client->close();
        });
    }

    public function testSocketIO(): void
    {
        $this->runInCoroutine(function (): void {
            $client = new SocketIOClient(self::$wsHost, self::$wsPort, false, 5);
            $client->connect(['uid' => 'socketio-user']);
            $this->assertNotSame('', $client->getSid());

            $joinAck = $client->emitWithAck('group.join', [['group' => 'public']], 5);
            $this->assertSame(0, $joinAck[0]['code'] ?? -1);

            $sendAck = $client->emitWithAck('chat.send', [[
                'group' => 'public',
                'message' => 'hello socket.io',
            ]], 5);
            $this->assertSame(0, $sendAck[0]['code'] ?? -1);

            $client->close();
        });
    }

    public function testPrivateChat(): void
    {
        $this->runInCoroutine(function (): void {
            $receiver = new SocketIOClient(self::$wsHost, self::$wsPort, false, 5);
            $receiver->connect(['uid' => 'smoke-user-b']);

            $sender = new SocketIOClient(self::$wsHost, self::$wsPort, false, 5);
            $sender->connect(['uid' => 'smoke-user-a']);

            $ack = $sender->emitWithAck('chat.private', [[
                'to_user_id' => 'smoke-user-b',
                'message' => 'hello private',
            ]], 5);
            $this->assertSame(0, $ack[0]['code'] ?? -1);

            $sender->close();
            $receiver->close();
        });
    }
}

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

use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\Frame;
use Swoolefy\Exception\SystemException;

class SocketIOClient
{
    private string $host;

    private int $port;

    private bool $ssl;

    private float $timeout;

    private string $path;

    private ?Client $client = null;

    private string $sid = '';

    private int $ackSeq = 0;

    public function __construct(string $host, int $port, bool $ssl = false, float $timeout = 5.0, string $path = '/socket.io/')
    {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->timeout = $timeout;
        $this->path = $path;
    }

    public function connect(array $query = [], array $headers = [], string $namespace = '/'): bool
    {
        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set(['timeout' => $this->timeout]);
        foreach ($headers as $name => $value) {
            $client->setHeaders([$name => $value]);
        }

        $query = array_merge(['EIO' => 4, 'transport' => 'websocket'], $query);
        $uri = $this->path . '?' . http_build_query($query);
        // Socket.IO v4 这里直接升级到 websocket transport，不走 HTTP long-polling。
        if (!$client->upgrade($uri)) {
            throw new SystemException('SocketIOClient upgrade failed: ' . ($client->errMsg ?: $client->errCode));
        }

        $this->client = $client;
        // 服务端第一帧必须是 Engine.IO open: 0{"sid":"..."}。
        $open = $this->recvRaw();
        if ($open === '' || $open[0] !== SocketIOPacket::ENGINE_OPEN) {
            throw new SystemException('SocketIOClient invalid Engine.IO open packet');
        }

        $handshake = json_decode(substr($open, 1), true);
        if (!is_array($handshake) || empty($handshake['sid'])) {
            throw new SystemException('SocketIOClient missing sid in open packet');
        }
        $this->sid = (string) $handshake['sid'];

        // Engine.IO open 后还需要发送 Socket.IO namespace connect，默认命名空间是 "/"。
        $this->sendRaw(SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_CONNECT . ($namespace !== '/' ? $namespace . ',' : ''));
        $connectAck = $this->recvRaw();
        if (!str_starts_with($connectAck, SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_CONNECT)) {
            throw new SystemException('SocketIOClient namespace connect failed: ' . $connectAck);
        }

        return true;
    }

    public function emit(string $event, array $args = [], ?float $timeout = null): bool
    {
        $this->sendRaw(SocketIOPacket::event($event, $args));
        return true;
    }

    public function emitWithAck(string $event, array $args = [], ?float $timeout = null): array
    {
        $id = (string) (++$this->ackSeq);
        // 带 id 的 event 会触发服务端返回 43{id}[...] ack 包，用于测试或请求-响应场景。
        $this->sendRaw(SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_EVENT . $id . json_encode(array_merge([$event], $args), JSON_UNESCAPED_UNICODE));

        $deadline = microtime(true) + ($timeout ?? $this->timeout);
        while (microtime(true) <= $deadline) {
            $raw = $this->recvRaw(max(0.1, $deadline - microtime(true)));
            if ($raw === SocketIOPacket::ENGINE_PING) {
                // 等待 ack 时也要处理服务端心跳，避免长请求期间被误断开。
                $this->sendRaw(SocketIOPacket::pong());
                continue;
            }
            if (str_starts_with($raw, SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_ACK . $id)) {
                $payload = substr($raw, 2 + strlen($id));
                $ack = json_decode($payload, true);
                return is_array($ack) ? $ack : [];
            }
        }

        throw new SystemException('SocketIOClient ack timeout');
    }

    public function recv(?float $timeout = null): array
    {
        $raw = $this->recvRaw($timeout);
        if ($raw === SocketIOPacket::ENGINE_PING) {
            // 公开 recv 同样自动响应 Engine.IO 心跳，调用方只需处理业务 event。
            $this->sendRaw(SocketIOPacket::pong());
            return ['type' => 'ping'];
        }

        $packet = SocketIOPacket::parse($raw);
        return [
            'engine_type' => $packet->engineType,
            'socket_type' => $packet->socketType,
            'namespace' => $packet->namespace,
            'event' => $packet->event,
            'args' => $packet->args,
            'id' => $packet->id,
        ];
    }

    public function close(): void
    {
        if ($this->client) {
            $this->sendRaw(SocketIOPacket::ENGINE_CLOSE);
            $this->client->close();
            $this->client = null;
        }
    }

    public function getSid(): string
    {
        return $this->sid;
    }

    private function sendRaw(string $payload): void
    {
        if (!$this->client) {
            throw new SystemException('SocketIOClient is not connected');
        }
        if ($this->client->push($payload) === false) {
            throw new SystemException('SocketIOClient send failed');
        }
    }

    private function recvRaw(?float $timeout = null): string
    {
        if (!$this->client) {
            throw new SystemException('SocketIOClient is not connected');
        }

        $oldTimeout = null;
        if ($timeout !== null) {
            $oldTimeout = $this->timeout;
            $this->client->set(['timeout' => $timeout]);
        }

        $frame = $this->client->recv();
        if ($oldTimeout !== null) {
            $this->client->set(['timeout' => $oldTimeout]);
        }

        if (!$frame instanceof Frame) {
            throw new SystemException('SocketIOClient recv timeout or connection closed');
        }

        return (string) $frame->data;
    }
}

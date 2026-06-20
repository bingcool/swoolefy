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

/**
 * Socket.IO v4 协程测试客户端（基于 Swoole\Http\Client WebSocket 升级）。
 *
 * ## 用途
 *
 * - 单测 / 冒烟测试（见 `src/Websocket/Tests/WebsocketSmokeTest.php`）
 * - 脚本侧模拟浏览器 emit / 收包，无需启动 Node 或浏览器
 * - 与框架 `SocketIOHandler` + `SocketIOPacket` 编解码约定一致
 *
 * ## 协议栈（EIO=4）
 *
 * ```
 * WebSocket 帧
 *   └─ Engine.IO 包类型（首字符 0~4）
 *        └─ Socket.IO 包类型（ENGINE_MESSAGE=4 后的首字符 0~4）
 *             └─ namespace / ack id / JSON payload
 * ```
 *
 * 典型握手序列：
 *
 * ```
 * 客户端 --HTTP upgrade--> 服务端
 * 服务端 --> 0{"sid":"..."}              Engine.IO open
 * 客户端 --> 40                          Socket.IO connect（默认 namespace /）
 * 服务端 --> 40                            connect ack
 * 客户端 --> 42["chat.send",{...}]       event（无 ack）
 * 客户端 --> 421["chat.send",{...}]      event（带 ack id=1）
 * 服务端 --> 431[...]                      ack 响应
 * 服务端 --> 2                             Engine.IO ping
 * 客户端 --> 3                             pong
 * ```
 *
 * ## 与浏览器客户端的差异
 *
 * - 仅实现 **WebSocket transport**，不走 HTTP long-polling
 * - 默认 namespace `/`；自定义 namespace 通过 `connect(..., $namespace)` 传入
 * - `recv()` / `emitWithAck()` 内自动回复 Engine.IO ping，调用方无需处理 `2`/`3`
 * - 未实现 Socket.IO 重连、二进制附件、多 namespace 并发
 *
 * ## 连接参数
 *
 * `connect()` 的 `$query` 会合并 `EIO=4&transport=websocket`，可传：
 * - `uid` / `user_id`：绑定集群 pushToUser
 * - `token`：鉴权（与 `Config/websocket.php` auth 配置一致）
 *
 * @see SocketIOPacket
 * @see SocketIOHandler
 */
class SocketIOClient
{
    private string $host;

    private int $port;

    private bool $ssl;

    private float $timeout;

    /** Socket.IO 握手路径，默认 `/socket.io/` */
    private string $path;

    private ?Client $client = null;

    /** Engine.IO open 包中的 session id */
    private string $sid = '';

    /** emitWithAck 自增 ack id，对应包体中的数字前缀 */
    private int $ackSeq = 0;

    /**
     * @param string $host    服务端 host
     * @param int    $port    端口
     * @param bool   $ssl     是否 wss
     * @param float  $timeout 收发包超时（秒），emitWithAck 默认也用此值
     * @param string $path    握手路径，需与服务端 socket.io path 一致
     */
    public function __construct(string $host, int $port, bool $ssl = false, float $timeout = 5.0, string $path = '/socket.io/')
    {
        $this->host = $host;
        $this->port = $port;
        $this->ssl = $ssl;
        $this->timeout = $timeout;
        $this->path = $path;
    }

    /**
     * 建立 WebSocket 并完成 Engine.IO + Socket.IO 双层握手。
     *
     * @param array  $query     附加 query 参数（uid、token 等）
     * @param array  $headers   额外 HTTP 头（如 Authorization）
     * @param string $namespace Socket.IO namespace，默认 `/`
     *
     * @throws SystemException 升级失败、open 包非法、namespace connect 失败
     */
    public function connect(array $query = [], array $headers = [], string $namespace = '/'): bool
    {
        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set(['timeout' => $this->timeout]);
        foreach ($headers as $name => $value) {
            $client->setHeaders([$name => $value]);
        }

        $query = array_merge(['EIO' => 4, 'transport' => 'websocket'], $query);
        $uri = $this->path . '?' . http_build_query($query);
        // 直接 WebSocket 升级，跳过 polling
        if (!$client->upgrade($uri)) {
            throw new SystemException('SocketIOClient upgrade failed: ' . ($client->errMsg ?: $client->errCode));
        }

        $this->client = $client;

        // 第一帧必须是 Engine.IO open：0{"sid":"...","pingInterval":...}
        $open = $this->recvRaw();
        if ($open === '' || $open[0] !== SocketIOPacket::ENGINE_OPEN) {
            throw new SystemException('SocketIOClient invalid Engine.IO open packet');
        }

        $handshake = json_decode(substr($open, 1), true);
        if (!is_array($handshake) || empty($handshake['sid'])) {
            throw new SystemException('SocketIOClient missing sid in open packet');
        }
        $this->sid = (string) $handshake['sid'];

        // namespace connect：默认 `/` 发 `40`；非默认如 `/admin` 发 `40/admin,`
        $this->sendRaw(SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_CONNECT . ($namespace !== '/' ? $namespace . ',' : ''));
        $connectAck = $this->recvRaw();
        if (!str_starts_with($connectAck, SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_CONNECT)) {
            throw new SystemException('SocketIOClient namespace connect failed: ' . $connectAck);
        }

        return true;
    }

    /**
     * 发送事件（fire-and-forget，不等待服务端 ack）。
     *
     * 编码为 `42["eventName", arg1, arg2, ...]`，与浏览器 `socket.emit('event', data)` 一致。
     *
     * @param string     $event   事件名，对应 Config/socketio.php event_routes
     * @param array      $args    参数列表（会 JSON 编码进数组）
     * @param float|null $timeout 保留参数，当前未使用
     */
    public function emit(string $event, array $args = [], ?float $timeout = null): bool
    {
        $this->sendRaw(SocketIOPacket::event($event, $args));

        return true;
    }

    /**
     * 发送带 ack 的事件，阻塞直到收到 `43{id}[...]` 或超时。
     *
     * 用于需要服务端返回结果的调用（如 joinGroup 返回、业务错误码）。
     * 等待期间自动响应 Engine.IO ping，避免长业务处理导致心跳超时断连。
     *
     * @param string     $event   事件名
     * @param array      $args    参数列表
     * @param float|null $timeout 等待 ack 秒数，默认构造时的 timeout
     *
     * @return array ack 载荷 JSON 解码结果，通常为 `[{ code, msg, data }]`
     *
     * @throws SystemException 超时未收到匹配 id 的 ack
     */
    public function emitWithAck(string $event, array $args = [], ?float $timeout = null): array
    {
        $id = (string) (++$this->ackSeq);
        // 42 + ackId + JSON，例如 421["chat.send",{"group":"public"}]
        $this->sendRaw(SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_EVENT . $id . json_encode(array_merge([$event], $args), JSON_UNESCAPED_UNICODE));

        $deadline = microtime(true) + ($timeout ?? $this->timeout);
        while (microtime(true) <= $deadline) {
            $raw = $this->recvRaw(max(0.1, $deadline - microtime(true)));
            if ($raw === SocketIOPacket::ENGINE_PING) {
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

    /**
     * 阻塞接收一帧并解析为结构化数组。
     *
     * - 收到 Engine.IO ping(`2`) 时自动 pong，返回 `['type'=>'ping']`
     * - 业务事件返回 engine_type / socket_type / event / args 等字段
     *
     * @param float|null $timeout 本次 recv 超时（秒）
     *
     * @return array{
     *     type?: string,
     *     engine_type?: string,
     *     socket_type?: string,
     *     namespace?: string,
     *     event?: string,
     *     args?: array,
     *     id?: string
     * }
     */
    public function recv(?float $timeout = null): array
    {
        $raw = $this->recvRaw($timeout);
        if ($raw === SocketIOPacket::ENGINE_PING) {
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

    /**
     * 发送 Engine.IO close(`1`) 并关闭底层 WebSocket。
     */
    public function close(): void
    {
        if ($this->client) {
            $this->sendRaw(SocketIOPacket::ENGINE_CLOSE);
            $this->client->close();
            $this->client = null;
        }
    }

    /** Engine.IO 握手得到的 sid，排查连接问题时可用 */
    public function getSid(): string
    {
        return $this->sid;
    }

    /** 发送原始 Engine.IO / Socket.IO 文本帧（opcode TEXT） */
    private function sendRaw(string $payload): void
    {
        if (!$this->client) {
            throw new SystemException('SocketIOClient is not connected');
        }
        if ($this->client->push($payload) === false) {
            throw new SystemException('SocketIOClient send failed');
        }
    }

    /**
     * 阻塞接收一帧 WebSocket 文本数据。
     *
     * 可临时覆盖 client timeout（用于 emitWithAck 轮询剩余时间）。
     */
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

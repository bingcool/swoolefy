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
 * - 单测 / 冒烟测试（见 `PHPUintTest/Websocket/WebsocketSmokeTest.php`）
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
 * - 仅实现 **WebSocket transport**（不走 HTTP long-polling；服务端见 SocketIOPollingHandler）
 * - 单连接单 namespace：`connect(..., $namespace)` 发一次 `40`；多 ns 需多次 connect 或多个实例
 * - `recv()` / `emitWithAck()` 内自动回复 Engine.IO ping
 * - 支持二进制 emit（自动识别 `SocketIOBinaryData::wrap()` 或非 UTF-8 字符串）
 * - 可选自动重连（默认开启，断线后按配置回连）
 *
 * ## 服务端已支持（本客户端暂未封装）
 *
 * - 多 namespace 并发（`40/chat,` + `40/admin,`）
 * - 二进制附件（`SocketIOBinaryData::wrap` + `encodeEventFrames`）
 * - long-polling transport
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

    /** 当前连接 namespace（用于 emit/ack 匹配） */
    private string $namespace = '/';

    /** 最近一次 connect 参数，供断线重连复用 */
    private array $lastConnectQuery = [];
    private array $lastConnectHeaders = [];
    private bool $hasReconnectContext = false;

    /** 自动重连开关与策略 */
    private bool $autoReconnect = true;
    private int $reconnectAttempts = 3;
    private float $reconnectDelay = 0.2;

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
     * 自动重连策略配置。
     *
     * @param bool  $enable   是否开启自动重连
     * @param int   $attempts 重试次数（>=1）
     * @param float $delay    每次重试前等待秒数（>=0）
     *
     * 说明：
     * - 仅在 send/recv 发现“连接已断开”时触发（业务超时不触发）
     * - 重连成功后会复用最近一次 connect() 的 query/header/namespace
     * - 重连不做指数退避，保持固定 delay，便于脚本端可预测时延
     */
    public function withReconnect(bool $enable = true, int $attempts = 3, float $delay = 0.2): self
    {
        $this->autoReconnect = $enable;
        $this->reconnectAttempts = max(1, $attempts);
        $this->reconnectDelay = max(0.0, $delay);

        return $this;
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
        $namespace = trim($namespace) !== '' ? $namespace : '/';
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
        $this->namespace = $namespace;
        $this->lastConnectQuery = $query;
        $this->lastConnectHeaders = $headers;
        $this->hasReconnectContext = true;

        // namespace connect：默认 `/` 发 `40`；非默认如 `/admin` 发 `40/admin,`
        // 注意：服务端可能先发 ping 或其他系统包，这里循环直到收到目标 namespace 的 connect ack。
        $this->sendRaw(SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_CONNECT . ($namespace !== '/' ? $namespace . ',' : ''));
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $connectAck = $this->recvRaw(max(0.1, $deadline - microtime(true)));
            if ($connectAck === SocketIOPacket::ENGINE_PING) {
                $this->sendRaw(SocketIOPacket::pong());
                continue;
            }

            $packet = SocketIOPacket::parse($connectAck);
            if ($packet->engineType === SocketIOPacket::ENGINE_MESSAGE
                && $packet->socketType === SocketIOPacket::SOCKET_CONNECT
                && ($packet->namespace === '/' || $packet->namespace === $namespace)) {
                break;
            }

            if (microtime(true) >= $deadline) {
                throw new SystemException('SocketIOClient namespace connect failed: ' . $connectAck);
            }
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
     *
     * 二进制行为：
     * - `SocketIOBinaryData::wrap($bytes)` 会编码成 binary event（45-... + BINARY 帧）
     * - 非 UTF-8 字符串也会自动作为附件处理（见 SocketIOBinaryData::prepareArgs）
     */
    public function emit(string $event, array $args = [], ?float $timeout = null): bool
    {
        unset($timeout);
        $this->sendEventFrames($this->buildEventFrames($event, $args));

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
        $this->sendEventFrames($this->buildEventFrames($event, $args, $id));

        $deadline = microtime(true) + ($timeout ?? $this->timeout);
        while (microtime(true) <= $deadline) {
            $raw = $this->recvRaw(max(0.1, $deadline - microtime(true)));
            if ($raw === SocketIOPacket::ENGINE_PING) {
                $this->sendRaw(SocketIOPacket::pong());
                continue;
            }

            $packet = SocketIOPacket::parse($raw);
            // 必须同时匹配：engine=4、socketType=ACK、ackId、namespace，避免串包误判
            if ($packet->engineType === SocketIOPacket::ENGINE_MESSAGE
                && in_array($packet->socketType, [SocketIOPacket::SOCKET_ACK, SocketIOPacket::SOCKET_BINARY_ACK], true)
                && $packet->id === $id
                && ($packet->namespace === '/' || $packet->namespace === $this->namespace)) {
                return $packet->args;
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
     *
     * 主动 close 属业务意图，不触发自动重连。
     */
    public function close(): void
    {
        if ($this->client) {
            // close 主动断开不触发重连
            $this->client->push(SocketIOPacket::ENGINE_CLOSE);
            $this->client->close();
            $this->client = null;
            $this->sid = '';
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
            if ($this->tryReconnect()) {
                if (!$this->client || $this->client->push($payload) === false) {
                    throw new SystemException('SocketIOClient send failed after reconnect');
                }
                return;
            }

            throw new SystemException('SocketIOClient send failed');
        }
    }

    /**
     * 阻塞接收一帧 WebSocket 文本数据。
     *
     * 可临时覆盖 client timeout（用于 emitWithAck 轮询剩余时间）。
     *
     * 设计细节：
     * - timeout=null：认为是“长连接常规收包”，允许一次自动重连后继续 recv
     * - timeout!=null：通常是业务侧限时等待（如 ack 轮询），不自动重连，避免扩大等待窗口
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
            if ($timeout === null && $this->tryReconnect()) {
                $frame = $this->client?->recv();
                if ($frame instanceof Frame) {
                    return (string) $frame->data;
                }
            }

            throw new SystemException('SocketIOClient recv timeout or connection closed');
        }

        return (string) $frame->data;
    }

    /**
     * 将 emit 参数编码为 Socket.IO 出站帧集合。
     *
     * 无附件：
     * - `42[... ]`（有 ack id 时 `42{id}[...]`，含 namespace 前缀）
     *
     * 有附件：
     * - 文本头：`45-<N>-[...]` / `45<id>-<N>-[...]`
     * - 后续 N 个二进制帧：`4` + bytes（Engine MESSAGE + payload）
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function buildEventFrames(string $event, array $args = [], string $ackId = ''): array
    {
        [$jsonArgs, $attachments] = SocketIOBinaryData::prepareArgs(array_merge([$event], $args));
        $eventName = (string) ($jsonArgs[0] ?? $event);
        $eventArgs = array_slice(is_array($jsonArgs) ? $jsonArgs : [], 1);

        if ($attachments === []) {
            $prefix = SocketIOPacket::ENGINE_MESSAGE . SocketIOPacket::SOCKET_EVENT;
            if ($this->namespace !== '/') {
                $prefix .= $this->namespace . ',';
            }
            $prefix .= $ackId;
            $payload = $prefix . json_encode(array_merge([$eventName], $eventArgs), JSON_UNESCAPED_UNICODE);

            return [[$payload, WEBSOCKET_OPCODE_TEXT]];
        }

        $text = SocketIOPacket::binaryEvent($eventName, $eventArgs, count($attachments), $this->namespace, $ackId);
        $frames = [[$text, WEBSOCKET_OPCODE_TEXT]];
        foreach ($attachments as $attachment) {
            $frames[] = [SocketIOBinaryData::encodeAttachmentFrame($attachment), WEBSOCKET_OPCODE_BINARY];
        }

        return $frames;
    }

    /**
     * 发送整组事件帧（文本+附件）并保证“要么整组成功，要么重连后整组重发”。
     *
     * 这样可避免二进制场景中“文本头已发、附件未发”的半包状态。
     *
     * @param array<int, array{0: string, 1: int}> $frames
     */
    private function sendEventFrames(array $frames): void
    {
        if ($this->pushFramesNoReconnect($frames)) {
            return;
        }

        if (!$this->tryReconnect() || !$this->pushFramesNoReconnect($frames)) {
            throw new SystemException('SocketIOClient send frames failed');
        }
    }

    /**
     * 低层发送：不触发重连，返回是否完整发送成功。
     *
     * @internal 由 sendEventFrames / sendRaw 控制是否触发重连
     *
     * @param array<int, array{0: string, 1: int}> $frames
     */
    private function pushFramesNoReconnect(array $frames): bool
    {
        if (!$this->client) {
            return false;
        }

        foreach ($frames as [$payload, $opcode]) {
            if ($this->client->push($payload, $opcode) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 尝试重连并恢复 Socket.IO 会话上下文（namespace/query/header）。
     *
     * 返回 true 表示已重连且握手成功；false 表示未启用重连或缺少上下文。
     * 重连失败会抛 SystemException（携带最后一次错误信息）。
     */
    private function tryReconnect(): bool
    {
        if (!$this->autoReconnect || !$this->hasReconnectContext) {
            return false;
        }

        $this->client?->close();
        $this->client = null;
        $lastError = null;
        for ($i = 0; $i < $this->reconnectAttempts; $i++) {
            if ($this->reconnectDelay > 0) {
                \Swoole\Coroutine::sleep($this->reconnectDelay);
            }
            try {
                return $this->connect($this->lastConnectQuery, $this->lastConnectHeaders, $this->namespace);
            } catch (\Throwable $throwable) {
                $lastError = $throwable;
            }
        }

        if ($lastError instanceof \Throwable) {
            throw new SystemException('SocketIOClient reconnect failed: ' . $lastError->getMessage(), 0, $lastError);
        }

        return false;
    }
}

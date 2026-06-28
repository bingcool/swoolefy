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

/**
 * Socket.IO / Engine.IO v4 文本帧编解码（EIO=4）。
 *
 * ## 帧结构
 *
 * ```
 * [Engine.IO 类型 1 字符][Socket.IO 类型?][namespace?,][ackId?][JSON payload?]
 * ```
 *
 * 示例：
 * - `0{"sid":"abc"}`           Engine open
 * - `2` / `3`                  ping / pong
 * - `40` / `40{"sid":"..."}`   namespace connect / ack
 * - `42["chat.send",{"k":"v"}]` event
 * - `431[{"code":0}]`          ack id=1 的响应
 * - `42/admin,7["evt",{}]`     非默认 namespace
 * - `45-1-["file",{_placeholder}]` + BINARY 帧   二进制 event
 *
 * ## 二进制附件格式（Socket.IO v4）
 *
 * 文本帧：`4` + `5` + `[ns,]` + `[ackId]` + `-N-` + JSON（含 `_placeholder`）
 * 后续 N 个 WebSocket BINARY 帧：首字节 `4`（Engine MESSAGE）+ 原始字节
 * polling POST：文本包与 `b<base64>` 附件块用 `\x1e` 分隔
 *
 * ## 职责
 *
 * - `parse()`：入站帧 → 结构化字段（event、args、data、id、attachmentCount）
 * - `open/event/ack/error/encodeEventFrames`：出站帧构造
 *
 * 服务端处理流程见 `SocketIOHandler`；测试客户端见 `SocketIOClient`。
 *
 * @see SocketIOHandler
 * @see SocketIOClient
 */
class SocketIOPacket
{
    // -------------------------------------------------------------------------
    // Engine.IO 包类型（WebSocket 文本帧首字符）
    // -------------------------------------------------------------------------

    /** 握手 open：服务端 onOpen 首包 `0{json}` */
    public const ENGINE_OPEN = '0';

    /** 连接关闭 */
    public const ENGINE_CLOSE = '1';

    /** 心跳 ping，服务端/客户端收到后须回复 ENGINE_PONG */
    public const ENGINE_PING = '2';

    /** 心跳 pong */
    public const ENGINE_PONG = '3';

    /**
     * 承载 Socket.IO 层消息。
     * 后续字符为 Socket.IO 包类型（0~4）+ 可选 namespace + 可选 ack id + JSON。
     */
    public const ENGINE_MESSAGE = '4';

    // -------------------------------------------------------------------------
    // Socket.IO 包类型（出现在 ENGINE_MESSAGE 之后的首字符）
    // -------------------------------------------------------------------------

    /** namespace 连接：客户端 `40`，服务端 ack `40{sid}` */
    public const SOCKET_CONNECT = '0';

    /** namespace 断开 */
    public const SOCKET_DISCONNECT = '1';

    /** 事件：客户端 `42[...]`，服务端推送同样用 SOCKET_EVENT 编码 */
    public const SOCKET_EVENT = '2';

    /** 应答：服务端 `43{id}[...]` 响应客户端带 id 的 event */
    public const SOCKET_ACK = '3';

    /** 错误包 */
    public const SOCKET_ERROR = '4';

    /** 带二进制附件的事件 */
    public const SOCKET_BINARY_EVENT = '5';

    /** 带二进制附件的 ack */
    public const SOCKET_BINARY_ACK = '6';

    // -------------------------------------------------------------------------
    // 解析结果字段
    // -------------------------------------------------------------------------

    /** Engine.IO 类型字符，见 ENGINE_* 常量 */
    public string $engineType = '';

    /** Socket.IO 类型字符，仅 engineType=4 时有效 */
    public string $socketType = '';

    /** namespace，默认 `/` */
    public string $namespace = '/';

    /** 客户端 event 附带的 ack id（包体数字前缀），空表示不需要 ack */
    public string $id = '';

    /** SOCKET_EVENT 时 JSON 数组第一个元素：事件名 */
    public string $event = '';

    /** SOCKET_EVENT 时 JSON 数组第 2..n 个参数 */
    public array $args = [];

    /**
     * 业务便捷字段：SOCKET_EVENT 时取 args[0]（须为 array，否则包装为 ['value'=>...]）。
     * 供 WebsocketHandler 作为路由 params 使用。
     */
    public array $data = [];

    /** 二进制附件数量；包体中 `-N-` 表示后续还有 N 个附件帧/块 */
    public int $attachmentCount = 0;

    /**
     * 解析客户端或服务端发出的 Socket.IO 文本帧。
     *
     * 支持格式示例：
     * - `2` / `3`                     ping / pong
     * - `42["chat.send",{"group":"public"}]`
     * - `421["chat.send",{}]`         带 ack id=1
     * - `42/admin,7["evt",{}]`        非默认 namespace
     *
     * @throws \InvalidArgumentException 空包、非法 event JSON
     */
    public static function parse(string $raw): self
    {
        $packet = new self();
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Socket.IO packet is empty');
        }

        $packet->engineType = $raw[0];
        if ($packet->engineType !== self::ENGINE_MESSAGE) {
            // `2probe` → pong 须为 `3probe`（Engine.IO upgrade 探测）
            if (strlen($raw) > 1) {
                $packet->data = ['engine_payload' => substr($raw, 1)];
            }

            return $packet;
        }

        $body = substr($raw, 1);
        if ($body === '') {
            throw new \InvalidArgumentException('Socket.IO message body is empty');
        }

        $packet->socketType = $body[0];
        $body = substr($body, 1);

        if ($body !== '' && $body[0] === '/') {
            // namespace：/admin, 或单独 /admin
            $commaPos = strpos($body, ',');
            if ($commaPos === false) {
                $packet->namespace = $body;
                $body = '';
            } else {
                $packet->namespace = substr($body, 0, $commaPos);
                $body = substr($body, $commaPos + 1);
            }
        }

        if (preg_match('/^(\d+)(.*)$/', $body, $matches)) {
            $rest = $matches[2];
            // ackId 后接 `[`（纯 JSON）或 `-`（二进制附件计数）
            if ($rest !== '' && ($rest[0] === '[' || $rest[0] === '-')) {
                $packet->id = $matches[1];
                $body = $rest;
            }
        }

        // 二进制：`-N-` 双横线格式，N 为附件个数
        if ($body !== '' && $body[0] === '-') {
            $secondDash = strpos($body, '-', 1);
            if ($secondDash !== false) {
                $countPart = substr($body, 1, $secondDash - 1);
                if ($countPart !== '' && ctype_digit($countPart)) {
                    $packet->attachmentCount = (int) $countPart;
                    $body = substr($body, $secondDash + 1);
                }
            }
        }

        $isEventType = in_array($packet->socketType, [
            self::SOCKET_EVENT,
            self::SOCKET_ACK,
            self::SOCKET_BINARY_EVENT,
            self::SOCKET_BINARY_ACK,
        ], true);

        if ($isEventType && ($packet->socketType === self::SOCKET_EVENT || $packet->socketType === self::SOCKET_BINARY_EVENT)) {
            $args = json_decode($body, true);
            if (!is_array($args) || !isset($args[0]) || !is_string($args[0])) {
                throw new \InvalidArgumentException('Socket.IO event payload must be json array');
            }
            $packet->event = $args[0];
            $packet->args = array_slice($args, 1);
            $packet->data = $packet->args[0] ?? [];
            if (!is_array($packet->data)) {
                $packet->data = ['value' => $packet->data];
            }
        } elseif ($isEventType && ($packet->socketType === self::SOCKET_ACK || $packet->socketType === self::SOCKET_BINARY_ACK)) {
            $args = json_decode($body, true);
            $packet->args = is_array($args) ? $args : [];
        } elseif ($packet->socketType === self::SOCKET_CONNECT && $body !== '') {
            $data = json_decode($body, true);
            $packet->data = is_array($data) ? $data : [];
        }

        return $packet;
    }

    /**
     * 构造 Engine.IO open 包（服务端 onOpen 首帧）。
     *
     * @param int $pingInterval 秒（配置项），写入 JSON 时 ×1000 为毫秒
     * @param int $pingTimeout  秒
     * @param int $maxPayload   单包最大字节
     */
    public static function open(
        string $sid,
        int $pingInterval,
        int $pingTimeout,
        int $maxPayload,
        array $upgrades = []
    ): string {
        return self::ENGINE_OPEN . json_encode([
            'sid' => $sid,
            'upgrades' => array_values($upgrades),
            'pingInterval' => $pingInterval * 1000,
            'pingTimeout' => $pingTimeout * 1000,
            'maxPayload' => $maxPayload,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 合并多条 Engine.IO 包（polling HTTP body 使用 \\x1e 分隔）。
     *
     * @param string[] $packets
     */
    public static function encodeBatch(array $packets): string
    {
        $packets = array_values(array_filter($packets, static fn (string $packet): bool => $packet !== ''));
        if ($packets === []) {
            return '';
        }

        return implode("\x1e", $packets);
    }

    /**
     * 解析 polling HTTP body 中的多条 Engine.IO 包。
     *
     * @return string[]
     */
    public static function decodeBatch(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            return [];
        }

        $parts = explode("\x1e", $body);
        $packets = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $packets[] = $part;
            }
        }

        return $packets;
    }

    /** Engine.IO pong；$payload 非空时回复 `3{payload}`（如 probe → `3probe`） */
    public static function pong(string $payload = ''): string
    {
        return self::ENGINE_PONG . $payload;
    }

    /**
     * Socket.IO namespace connect 确认：`40` 或 `40/ns,` + `{"sid":"..."}`。
     */
    public static function connectAck(string $sid, string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_CONNECT
            . self::namespacePrefix($namespace)
            . json_encode(['sid' => $sid], JSON_UNESCAPED_UNICODE);
    }

    /** 是否为 Socket.IO namespace connect ack（`40` / `40/ns,{...}`） */
    public static function isConnectAck(string $packet): bool
    {
        if ($packet === '' || $packet[0] !== self::ENGINE_MESSAGE) {
            return false;
        }

        try {
            $parsed = self::parse($packet);

            return $parsed->socketType === self::SOCKET_CONNECT;
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    /**
     * 服务端主动推送事件：`42["eventName", ...args]`。
     *
     * 集群 push / pushToUser 最终经 WebsocketConnectionManager 调用。
     */
    public static function event(string $event, array $args = [], string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_EVENT
            . self::namespacePrefix($namespace)
            . json_encode(array_merge([$event], $args), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 编码出站 event：无附件 → 单 TEXT；有附件 → TEXT + 若干 BINARY。
     *
     * 业务 push 经 WebsocketConnectionManager::encodeEventFrames 调用。
     *
     * @return array<int, array{0: string, 1: int}> [payload, WEBSOCKET_OPCODE_*]
     */
    public static function encodeEventFrames(string $event, array $args = [], string $namespace = '/'): array
    {
        [$jsonArgs, $attachments] = SocketIOBinaryData::prepareArgs(array_merge([$event], $args));
        $eventName = (string) ($jsonArgs[0] ?? $event);
        $eventArgs = array_slice(is_array($jsonArgs) ? $jsonArgs : [], 1);

        if ($attachments === []) {
            return [[self::event($eventName, $eventArgs, $namespace), WEBSOCKET_OPCODE_TEXT]];
        }

        $text = self::binaryEvent($eventName, $eventArgs, count($attachments), $namespace);
        $frames = [[$text, WEBSOCKET_OPCODE_TEXT]];
        foreach ($attachments as $attachment) {
            $frames[] = [SocketIOBinaryData::encodeAttachmentFrame($attachment), WEBSOCKET_OPCODE_BINARY];
        }

        return $frames;
    }

    /** 构造二进制 event 文本头：`45-1-[...]` 或 `451-1-[...]`（带 ackId） */
    public static function binaryEvent(
        string $event,
        array $args,
        int $attachmentCount,
        string $namespace = '/',
        string $ackId = ''
    ): string {
        return self::ENGINE_MESSAGE
            . self::SOCKET_BINARY_EVENT
            . self::namespacePrefix($namespace)
            . $ackId
            . '-' . $attachmentCount . '-'
            . json_encode(array_merge([$event], $args), JSON_UNESCAPED_UNICODE);
    }

    /**
     * polling 出站：文本包 + base64 附件块。
     *
     * @return string[]
     */
    public static function encodeEventPollingChunks(string $event, array $args = [], string $namespace = '/'): array
    {
        $frames = self::encodeEventFrames($event, $args, $namespace);
        $chunks = [];
        foreach ($frames as [$payload, $opcode]) {
            if ($opcode === WEBSOCKET_OPCODE_BINARY) {
                $chunks[] = SocketIOBinaryData::encodePollingAttachment(
                    SocketIOBinaryData::decodeAttachmentFrame($payload)
                );
            } else {
                $chunks[] = $payload;
            }
        }

        return $chunks;
    }

    /**
     * 响应客户端带 id 的 event：`43{id}[...]`。
     *
     * @param string $id   与客户端包中的 ack id 一致
     * @param array  $args ack 载荷，通常为 `[['code'=>0,'msg'=>'ok']]`
     */
    public static function ack(string $id, array $args = [], string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_ACK
            . self::namespacePrefix($namespace)
            . $id
            . json_encode($args, JSON_UNESCAPED_UNICODE);
    }

    /** Socket.IO 层错误：`44{"message":"...","code":-1}` */
    public static function error(string $msg, int $code = -1, string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_ERROR
            . self::namespacePrefix($namespace)
            . json_encode(['message' => $msg, 'code' => $code], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 非默认 namespace 的前缀：`/admin,`；默认 `/` 返回空字符串。
     */
    private static function namespacePrefix(string $namespace): string
    {
        return $namespace !== '/' ? $namespace . ',' : '';
    }
}

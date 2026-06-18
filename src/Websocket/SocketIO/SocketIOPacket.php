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

class SocketIOPacket
{
    // Engine.IO packet type：Socket.IO v4 在 WebSocket transport 上仍包了一层 Engine.IO。
    public const ENGINE_OPEN = '0';
    public const ENGINE_CLOSE = '1';
    public const ENGINE_PING = '2';
    public const ENGINE_PONG = '3';
    public const ENGINE_MESSAGE = '4';

    // Socket.IO packet type：这些值出现在 Engine.IO message("4") 后面。
    public const SOCKET_CONNECT = '0';
    public const SOCKET_DISCONNECT = '1';
    public const SOCKET_EVENT = '2';
    public const SOCKET_ACK = '3';
    public const SOCKET_ERROR = '4';

    public string $engineType = '';

    public string $socketType = '';

    public string $namespace = '/';

    public string $id = '';

    public string $event = '';

    public array $args = [];

    public array $data = [];

    public static function parse(string $raw): self
    {
        $packet = new self();
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Socket.IO packet is empty');
        }

        $packet->engineType = $raw[0];
        if ($packet->engineType !== self::ENGINE_MESSAGE) {
            // open/close/ping/pong 只有 Engine.IO 层，不包含 Socket.IO body。
            return $packet;
        }

        $body = substr($raw, 1);
        if ($body === '') {
            throw new \InvalidArgumentException('Socket.IO message body is empty');
        }

        $packet->socketType = $body[0];
        $body = substr($body, 1);

        if ($body !== '' && $body[0] === '/') {
            // namespace 以 / 开头，并通过逗号与后续 id/payload 分隔，例如 42/admin,7["event"]。
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
            // event 后紧跟数字表示 ack id，例如 4212["chat.send",{}]。
            $packet->id = $matches[1];
            $body = $matches[2];
        }

        if ($packet->socketType === self::SOCKET_EVENT || $packet->socketType === self::SOCKET_ACK) {
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
        } elseif ($packet->socketType === self::SOCKET_CONNECT && $body !== '') {
            $data = json_decode($body, true);
            $packet->data = is_array($data) ? $data : [];
        }

        return $packet;
    }

    public static function open(string $sid, int $pingInterval, int $pingTimeout, int $maxPayload): string
    {
        // Engine.IO open 包中的时间单位是毫秒，框架配置使用秒。
        return self::ENGINE_OPEN . json_encode([
            'sid' => $sid,
            'upgrades' => [],
            'pingInterval' => $pingInterval * 1000,
            'pingTimeout' => $pingTimeout * 1000,
            'maxPayload' => $maxPayload,
        ], JSON_UNESCAPED_UNICODE);
    }

    public static function pong(): string
    {
        return self::ENGINE_PONG;
    }

    public static function connectAck(string $sid, string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_CONNECT
            . self::namespacePrefix($namespace)
            . json_encode(['sid' => $sid], JSON_UNESCAPED_UNICODE);
    }

    public static function event(string $event, array $args = [], string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_EVENT
            . self::namespacePrefix($namespace)
            . json_encode(array_merge([$event], $args), JSON_UNESCAPED_UNICODE);
    }

    public static function ack(string $id, array $args = [], string $namespace = '/'): string
    {
        // ack 格式：43{id}[...args]，命名空间非 "/" 时是 43/ns,{id}[...args]。
        return self::ENGINE_MESSAGE
            . self::SOCKET_ACK
            . self::namespacePrefix($namespace)
            . $id
            . json_encode($args, JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $msg, int $code = -1, string $namespace = '/'): string
    {
        return self::ENGINE_MESSAGE
            . self::SOCKET_ERROR
            . self::namespacePrefix($namespace)
            . json_encode(['message' => $msg, 'code' => $code], JSON_UNESCAPED_UNICODE);
    }

    private static function namespacePrefix(string $namespace): string
    {
        return $namespace !== '/' ? $namespace . ',' : '';
    }
}

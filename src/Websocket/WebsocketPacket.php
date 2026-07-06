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

namespace Swoolefy\Websocket;

use Swoole\WebSocket\Frame;

class WebsocketPacket
{
    /**
     * 统一消息格式：
     * {
     *   "type": "request|event|ping",
     *   "event": "Service/Chat/Send",
     *   "request_id": "client-generated-id",
     *   "data": {}
     * }
     *
     * 同时兼容历史格式：Service/Demo/ReportMsg::{"msg":"hello"}
     */
    public const TYPE_REQUEST = 'request';
    public const TYPE_EVENT = 'event';
    public const TYPE_PING = 'ping';

    private int $fd;

    private string $raw;

    private string $endpoint;

    private array $params;

    private string $type;

    private string $requestId;

    private int $opcode;

    private bool $finish;

    private array $meta;

    public function __construct(
        int $fd,
        string $raw,
        string $endpoint,
        array $params,
        string $type = self::TYPE_REQUEST,
        string $requestId = '',
        int $opcode = WEBSOCKET_OPCODE_TEXT,
        bool $finish = true,
        array $meta = []
    ) {
        $this->fd = $fd;
        $this->raw = $raw;
        $this->endpoint = $endpoint;
        $this->params = $params;
        $this->type = $type;
        $this->requestId = $requestId;
        $this->opcode = $opcode;
        $this->finish = $finish;
        $this->meta = $meta;
    }

    public static function fromFrame(Frame $frame, string $delimiter = SWOOLEFY_EOF_FLAG): self
    {
        return self::parse(
            $frame->fd,
            (string) $frame->data,
            $delimiter,
            (int) $frame->opcode,
            (bool) $frame->finish
        );
    }

    public static function parse(
        int $fd,
        string $raw,
        string $delimiter = SWOOLEFY_EOF_FLAG,
        int $opcode = WEBSOCKET_OPCODE_TEXT,
        bool $finish = true
    ): self {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Websocket payload is empty');
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // 新协议优先：JSON 对象更适合扩展 request_id、type、meta 等字段。
            return self::fromJsonPayload($fd, $raw, $decoded, $opcode, $finish);
        }

        // 历史兼容：保留 endpoint::json，避免已有 WebSocket 客户端一次性破坏。
        $items = explode($delimiter, $raw, 2);
        if (count($items) !== 2) {
            throw new \InvalidArgumentException('Websocket payload parse error');
        }

        [$endpoint, $params] = $items;
        $endpoint = self::normalizeEndpoint((string) $endpoint);
        $params = json_decode((string) $params, true);
        if (!is_array($params)) {
            throw new \InvalidArgumentException('Websocket params must be valid json object');
        }

        return new self($fd, $raw, $endpoint, $params, self::TYPE_REQUEST, '', $opcode, $finish);
    }

    private static function fromJsonPayload(int $fd, string $raw, array $payload, int $opcode, bool $finish): self
    {
        // endpoint 是框架内部路由名；event 是给客户端更自然的字段名，两者都支持。
        $endpoint = (string) ($payload['event'] ?? $payload['endpoint'] ?? '');
        $endpoint = self::normalizeEndpoint($endpoint);
        if ($endpoint === '') {
            throw new \InvalidArgumentException('Websocket event is required');
        }

        $params = $payload['data'] ?? $payload['params'] ?? [];
        if (!is_array($params)) {
            throw new \InvalidArgumentException('Websocket data must be json object');
        }

        $type = (string) ($payload['type'] ?? self::TYPE_REQUEST);
        $requestId = (string) ($payload['request_id'] ?? $payload['requestId'] ?? '');

        return new self($fd, $raw, $endpoint, $params, $type, $requestId, $opcode, $finish, $payload);
    }

    private static function normalizeEndpoint(string $endpoint): string
    {
        return trim(str_replace('\\', DIRECTORY_SEPARATOR, $endpoint), DIRECTORY_SEPARATOR);
    }

    public function getFd(): int
    {
        return $this->fd;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getOpcode(): int
    {
        return $this->opcode;
    }

    public function isFinish(): bool
    {
        return $this->finish;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }
}

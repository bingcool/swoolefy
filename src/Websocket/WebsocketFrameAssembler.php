<?php

namespace Swoolefy\Websocket;

use Swoole\WebSocket\Frame;
use Swoolefy\Core\Swfy;

/**
 * WebSocket 分片帧重组（RFC 6455）。
 *
 * - 首帧：TEXT/BINARY + finish=false → 缓存
 * - 续帧：CONTINUATION(0) → 追加 data
 * - 末帧：finish=true → 返回完整 Frame 再进入业务解析
 */
class WebsocketFrameAssembler
{
    public const OPCODE_CONTINUE = 0x0;

    /** @var array<int, array{opcode:int,data:string}> */
    private static array $buffers = [];

    /**
     * @return Frame|null 完整帧；null 表示等待后续分片
     */
    public static function feed(Frame $frame): ?Frame
    {
        $fd = (int) $frame->fd;
        $opcode = (int) $frame->opcode;
        $finish = (bool) $frame->finish;
        $data = (string) $frame->data;

        if (self::isControlOpcode($opcode)) {
            self::clear($fd);
            return $frame;
        }

        if ($opcode === self::OPCODE_CONTINUE) {
            return self::feedContinuation($fd, $data, $finish);
        }

        if ($opcode === WEBSOCKET_OPCODE_TEXT || $opcode === WEBSOCKET_OPCODE_BINARY) {
            return self::feedDataFrame($fd, $opcode, $data, $finish);
        }

        self::clear($fd);
        return $frame;
    }

    public static function clear(int $fd): void
    {
        unset(self::$buffers[$fd]);
    }

    public static function reset(): void
    {
        self::$buffers = [];
    }

    private static function feedDataFrame(int $fd, int $opcode, string $data, bool $finish): ?Frame
    {
        if (!$finish) {
            if (isset(self::$buffers[$fd])) {
                throw new WebsocketFrameException('unexpected data frame while fragment buffer exists');
            }
            self::assertPayloadSize($fd, $data);
            self::$buffers[$fd] = ['opcode' => $opcode, 'data' => $data];
            return null;
        }

        self::clear($fd);
        return self::buildFrame($fd, $opcode, $data);
    }

    private static function feedContinuation(int $fd, string $data, bool $finish): ?Frame
    {
        if (!isset(self::$buffers[$fd])) {
            throw new WebsocketFrameException('continuation frame without initial fragment');
        }

        self::$buffers[$fd]['data'] .= $data;
        self::assertPayloadSize($fd, self::$buffers[$fd]['data']);

        if (!$finish) {
            return null;
        }

        $buffer = self::$buffers[$fd];
        self::clear($fd);

        return self::buildFrame($fd, (int) $buffer['opcode'], (string) $buffer['data']);
    }

    private static function buildFrame(int $fd, int $opcode, string $data): Frame
    {
        $frame = new Frame();
        $frame->fd = $fd;
        $frame->opcode = $opcode;
        $frame->data = $data;
        $frame->finish = true;

        return $frame;
    }

    private static function isControlOpcode(int $opcode): bool
    {
        return in_array($opcode, [
            WEBSOCKET_OPCODE_CLOSE,
            WEBSOCKET_OPCODE_PING,
            WEBSOCKET_OPCODE_PONG,
        ], true);
    }

    private static function assertPayloadSize(int $fd, string $data): void
    {
        if (strlen($data) > self::maxPayload()) {
            self::clear($fd);
            throw new WebsocketFrameException('fragment payload exceeds max_fragment_payload');
        }
    }

    private static function maxPayload(): int
    {
        try {
            $conf = Swfy::getConf();
            $websocket = is_array($conf['websocket'] ?? null) ? $conf['websocket'] : [];
            $max = (int) ($websocket['max_fragment_payload'] ?? 0);
            if ($max > 0) {
                return $max;
            }

            $setting = is_array($conf['setting'] ?? null) ? $conf['setting'] : [];

            return (int) ($setting['package_max_length'] ?? 2 * 1024 * 1024);
        } catch (\Throwable $throwable) {
            return 2 * 1024 * 1024;
        }
    }
}

<?php

namespace Swoolefy\Websocket\SocketIO;

/**
 * Socket.IO 二进制附件编解码（Engine.IO v4 + Socket.IO v4）。
 *
 * ## 业务层用法
 *
 * push / emit 时在 data 中显式包装：
 * ```php
 * 'content' => SocketIOBinaryData::wrap($rawBytes)
 * ```
 *
 * ## 传输格式
 *
 * | Transport  | 文本帧 | 附件 |
 * |------------|--------|------|
 * | WebSocket  | `45-1-[...]` | BINARY 帧，首字节 `4` + bytes |
 * | Polling    | `\x1e` 分隔 | `b` + base64 |
 */
class SocketIOBinaryData
{
    /** 业务层显式标记二进制字段；prepareArgs 会转为 `_placeholder` */
    public const WRAP_KEY = '__socketio_binary';

    /**
     * 将任意值包装为二进制附件占位（用于 push / emit）。
     */
    public static function wrap(string $bytes): array
    {
        return [self::WRAP_KEY => base64_encode($bytes)];
    }

    public static function isWrapped(mixed $value): bool
    {
        return is_array($value) && array_key_exists(self::WRAP_KEY, $value) && is_string($value[self::WRAP_KEY]);
    }

    public static function unwrap(mixed $value): string
    {
        if (!self::isWrapped($value)) {
            return '';
        }

        return (string) base64_decode((string) $value[self::WRAP_KEY], true);
    }

    /**
     * 扫描 args，将二进制替换为 `_placeholder` 并收集附件列表。
     *
     * @return array{0: array, 1: string[]}
     */
    public static function prepareArgs(array $args): array
    {
        $attachments = [];
        $prepared = self::prepareValue($args, $attachments);

        return [is_array($prepared) ? $prepared : $args, $attachments];
    }

    /**
     * 将收到的附件注入 JSON 结构，替换 `_placeholder`。
     */
    public static function injectAttachments(mixed $value, array $attachments): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (isset($value['_placeholder'], $value['num']) && $value['_placeholder'] === true) {
            // socket.io-parser 约定：num 对应 attachments 数组下标
            $index = (int) $value['num'];

            return $attachments[$index] ?? '';
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::injectAttachments($item, $attachments);
        }

        return $result;
    }

    /** WebSocket 二进制帧：Engine.IO MESSAGE(4) + 附件字节 */
    public static function encodeAttachmentFrame(string $bytes): string
    {
        return SocketIOPacket::ENGINE_MESSAGE . $bytes;
    }

    /** 解析 WebSocket 二进制帧，返回附件原始字节 */
    public static function decodeAttachmentFrame(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        if ($payload[0] === SocketIOPacket::ENGINE_MESSAGE) {
            return substr($payload, 1);
        }

        return $payload;
    }

    /** polling POST 附件：`b` + base64 */
    public static function encodePollingAttachment(string $bytes): string
    {
        return 'b' . base64_encode($bytes);
    }

    public static function decodePollingAttachment(string $chunk): ?string
    {
        if ($chunk === '' || $chunk[0] !== 'b') {
            return null;
        }

        $decoded = base64_decode(substr($chunk, 1), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param string[] $attachments
     */
    private static function prepareValue(mixed $value, array &$attachments): mixed
    {
        if (self::isWrapped($value)) {
            $bytes = self::unwrap($value);
            $attachments[] = $bytes;

            return ['_placeholder' => true, 'num' => count($attachments) - 1];
        }

        // 非 UTF-8 字符串自动视为二进制（如图片/文件原始 bytes）
        if (is_string($value) && $value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            $attachments[] = $value;

            return ['_placeholder' => true, 'num' => count($attachments) - 1];
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::prepareValue($item, $attachments);
        }

        return $result;
    }
}

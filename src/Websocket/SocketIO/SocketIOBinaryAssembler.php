<?php

namespace Swoolefy\Websocket\SocketIO;

/**
 * 按 fd 缓存未收齐的二进制 Socket.IO 包（WebSocket 分帧组装）。
 *
 * 典型时序：
 * 1. feedText 收到 `45-1-[...]` → 进入 pending，等待 1 个 BINARY 帧
 * 2. feedBinary 收到附件 → injectAttachments → 返回完整 SOCKET_EVENT
 * 3. close/clear 时丢弃 pending，避免 fd 复用串包
 */
class SocketIOBinaryAssembler
{
    /** @var array<int, array{packet: SocketIOPacket, expected: int, received: string[]}> fd → 半包状态 */
    private static array $pending = [];

    /**
     * 处理文本 Engine.IO 包；若需二进制附件则进入 pending，否则直接返回可 dispatch 的包。
     */
    public static function feedText(int $fd, SocketIOPacket $packet): ?SocketIOPacket
    {
        if ($packet->attachmentCount <= 0) {
            return $packet;
        }

        self::$pending[$fd] = [
            'packet' => $packet,
            'expected' => $packet->attachmentCount,
            'received' => [],
        ];

        return null;
    }

    /** 追加 WebSocket 二进制帧；收齐后返回完整包 */
    public static function feedBinary(int $fd, string $payload): ?SocketIOPacket
    {
        if (!isset(self::$pending[$fd])) {
            return null;
        }

        self::$pending[$fd]['received'][] = SocketIOBinaryData::decodeAttachmentFrame($payload);
        if (count(self::$pending[$fd]['received']) < self::$pending[$fd]['expected']) {
            return null;
        }

        $state = self::$pending[$fd];
        unset(self::$pending[$fd]);

        return self::finalizeBinaryPacket($state['packet'], $state['received']);
    }

    /** polling POST：文本包 + base64 附件列表 */
    public static function finalizeFromPolling(SocketIOPacket $packet, array $attachments): SocketIOPacket
    {
        return self::finalizeBinaryPacket($packet, $attachments);
    }

    public static function clear(int $fd): void
    {
        unset(self::$pending[$fd]);
    }

    public static function resetForTest(): void
    {
        self::$pending = [];
    }

    /**
     * @param string[] $attachments
     */
    private static function finalizeBinaryPacket(SocketIOPacket $packet, array $attachments): SocketIOPacket
    {
        // 归一化为 SOCKET_EVENT，供 handleParsedPacket 统一 dispatch
        if ($packet->socketType === SocketIOPacket::SOCKET_BINARY_EVENT
            || $packet->socketType === SocketIOPacket::SOCKET_EVENT) {
            $mergedArgs = SocketIOBinaryData::injectAttachments(
                array_merge([$packet->event], $packet->args),
                $attachments
            );
            $packet->event = (string) ($mergedArgs[0] ?? '');
            $packet->args = array_slice(is_array($mergedArgs) ? $mergedArgs : [], 1);
            $packet->data = $packet->args[0] ?? [];
            if (!is_array($packet->data)) {
                $packet->data = ['value' => $packet->data];
            }
            $packet->socketType = SocketIOPacket::SOCKET_EVENT;
        } elseif ($packet->socketType === SocketIOPacket::SOCKET_BINARY_ACK) {
            $packet->args = SocketIOBinaryData::injectAttachments($packet->args, $attachments);
            $packet->socketType = SocketIOPacket::SOCKET_ACK;
        }

        $packet->attachmentCount = 0;

        return $packet;
    }
}

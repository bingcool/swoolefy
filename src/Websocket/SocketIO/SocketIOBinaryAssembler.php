<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoolefy\Core\Swfy;

/**
 * 按 fd 缓存未收齐的二进制 Socket.IO 包（WebSocket 分帧组装）。
 *
 * 典型时序：
 * 1. feedText 收到 `45-1-[...]` → 进入 pending，等待 1 个 BINARY 帧
 * 2. feedBinary 收到附件 → injectAttachments → 返回完整 SOCKET_EVENT
 * 3. close/clear 时丢弃 pending，避免 fd 复用串包
 * 4. 心跳 tick 调用 {@see cleanupStale()} 清理空闲/超生命周期半包
 */
class SocketIOBinaryAssembler
{
    /** 默认附件空闲超时（秒） */
    public const DEFAULT_IDLE_TIMEOUT = 60;

    /** 默认从创建起的最大生命周期（秒）；持续小帧也不能无限续期 */
    public const DEFAULT_MAX_LIFETIME = 300;

    /** 默认单连接最大附件数 */
    public const DEFAULT_MAX_ATTACHMENTS = 32;

    /** 默认单连接已接收附件总字节上限 */
    public const DEFAULT_MAX_BYTES = 2097152;

    /**
     * @var array<int, array{
     *   packet: SocketIOPacket,
     *   expected: int,
     *   received: string[],
     *   received_bytes: int,
     *   created_at: int,
     *   updated_at: int
     * }>
     */
    private static array $pending = [];

    /**
     * 处理文本 Engine.IO 包；若需二进制附件则进入 pending，否则直接返回可 dispatch 的包。
     */
    public static function feedText(int $fd, SocketIOPacket $packet): ?SocketIOPacket
    {
        if ($packet->attachmentCount <= 0) {
            return $packet;
        }

        // 附件数量超限：拒绝进入 pending，避免无界等待
        if ($packet->attachmentCount > self::maxAttachments()) {
            self::clear($fd);
            throw new \RuntimeException('Socket.IO binary attachment count exceeds limit');
        }

        $now = time();
        self::$pending[$fd] = [
            'packet' => $packet,
            'expected' => $packet->attachmentCount,
            'received' => [],
            'received_bytes' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return null;
    }

    /** 追加 WebSocket 二进制帧；收齐后返回完整包 */
    public static function feedBinary(int $fd, string $payload): ?SocketIOPacket
    {
        if (!isset(self::$pending[$fd])) {
            return null;
        }

        $decoded = SocketIOBinaryData::decodeAttachmentFrame($payload);
        $bytes = strlen($decoded);
        $projected = self::$pending[$fd]['received_bytes'] + $bytes;
        if ($projected > self::maxBytes()) {
            self::clear($fd);
            throw new \RuntimeException('Socket.IO binary attachment bytes exceeds limit');
        }

        self::$pending[$fd]['received'][] = $decoded;
        self::$pending[$fd]['received_bytes'] = $projected;
        // 每次追加刷新空闲计时；总生命周期仍受 created_at 约束
        self::$pending[$fd]['updated_at'] = time();

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
     * 清理空闲超时或超过总生命周期的 pending assembler。
     *
     * @param int|null $now 当前 Unix 秒；null 则 time()
     * @param int $maxIdleSeconds 空闲秒数；≤0 读配置/默认
     * @param int $maxLifetimeSeconds 总生命周期；≤0 读配置/默认
     * @return int 本次清理的 fd 数量
     */
    public static function cleanupStale(?int $now = null, int $maxIdleSeconds = 0, int $maxLifeSeconds = 0): int
    {
        if (self::$pending === []) {
            return 0;
        }

        $now ??= time();
        if ($maxIdleSeconds <= 0) {
            $maxIdleSeconds = self::idleTimeout();
        }
        if ($maxLifeSeconds <= 0) {
            $maxLifeSeconds = self::maxLifetime();
        }

        $removed = 0;
        foreach (self::$pending as $fd => $state) {
            $updatedAt = (int) ($state['updated_at'] ?? 0);
            $createdAt = (int) ($state['created_at'] ?? 0);
            $idleExpired = $maxIdleSeconds > 0 && $updatedAt > 0 && ($now - $updatedAt) > $maxIdleSeconds;
            // 持续小帧刷新 updated_at 也不能越过总生命周期
            $lifeExpired = $maxLifeSeconds > 0 && $createdAt > 0 && ($now - $createdAt) > $maxLifeSeconds;
            if ($idleExpired || $lifeExpired) {
                unset(self::$pending[$fd]);
                $removed++;
            }
        }

        return $removed;
    }

    /** 当前 pending 连接数（测试 / 指标） */
    public static function pendingCount(): int
    {
        return count(self::$pending);
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

    private static function idleTimeout(): int
    {
        try {
            $socketio = self::socketioConf();
            $timeout = (int) ($socketio['binary_idle_timeout'] ?? 0);
            if ($timeout > 0) {
                return $timeout;
            }
        } catch (\Throwable $ignore) {
        }

        return self::DEFAULT_IDLE_TIMEOUT;
    }

    private static function maxLifetime(): int
    {
        try {
            $socketio = self::socketioConf();
            $lifetime = (int) ($socketio['binary_max_lifetime'] ?? 0);
            if ($lifetime > 0) {
                return $lifetime;
            }
        } catch (\Throwable $ignore) {
        }

        return self::DEFAULT_MAX_LIFETIME;
    }

    private static function maxAttachments(): int
    {
        try {
            $socketio = self::socketioConf();
            $max = (int) ($socketio['binary_max_attachments'] ?? 0);
            if ($max > 0) {
                return $max;
            }
        } catch (\Throwable $ignore) {
        }

        return self::DEFAULT_MAX_ATTACHMENTS;
    }

    private static function maxBytes(): int
    {
        try {
            $socketio = self::socketioConf();
            $max = (int) ($socketio['binary_max_bytes'] ?? 0);
            if ($max > 0) {
                return $max;
            }
        } catch (\Throwable $ignore) {
        }

        return self::DEFAULT_MAX_BYTES;
    }

    /** @return array<string, mixed> */
    private static function socketioConf(): array
    {
        $conf = Swfy::getConf();
        $websocket = is_array($conf['websocket'] ?? null) ? $conf['websocket'] : [];
        $socketio = $websocket['socketio'] ?? ($conf['socketio'] ?? []);

        return is_array($socketio) ? $socketio : [];
    }
}

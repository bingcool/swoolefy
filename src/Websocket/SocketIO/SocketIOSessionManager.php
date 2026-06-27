<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoole\Coroutine\Channel;

/**
 * Socket.IO polling 会话（sid → 出站队列 + long-poll 唤醒）。
 *
 * polling 连接无持久 WebSocket fd，使用虚拟 fd 写入 WebsocketConnectionManager，
 * 出站 push 写入本 Worker 内存队列，由 GET long-poll 取走。
 *
 * 多 Worker 部署需负载均衡会话粘性（同一 sid 落到同一 Worker）。
 */
class SocketIOSessionManager
{
    private const VIRTUAL_FD_BASE = 0x40000000;

    /** @var array<string, string[]> sid → 待发送 Engine.IO 包 */
    private static array $outbound = [];

    /** @var array<string, Channel> sid → long-poll 等待通道 */
    private static array $waitChannels = [];

    /** @var array<string, int> sid → 虚拟 fd */
    private static array $sidToVirtualFd = [];

    /** @var array<int, string> 虚拟 fd → sid */
    private static array $virtualFdToSid = [];

    private static int $nextVirtualFd = self::VIRTUAL_FD_BASE;

    public static function allocateVirtualFd(): int
    {
        return self::$nextVirtualFd++;
    }

    public static function bindSid(string $sid, int $virtualFd): void
    {
        self::$sidToVirtualFd[$sid] = $virtualFd;
        self::$virtualFdToSid[$virtualFd] = $sid;
        self::$outbound[$sid] = self::$outbound[$sid] ?? [];
    }

    public static function hasSession(string $sid): bool
    {
        return isset(self::$sidToVirtualFd[$sid]);
    }

    public static function getVirtualFd(string $sid): int
    {
        return (int) (self::$sidToVirtualFd[$sid] ?? 0);
    }

    public static function getSidByVirtualFd(int $virtualFd): string
    {
        return (string) (self::$virtualFdToSid[$virtualFd] ?? '');
    }

    public static function isVirtualFd(int $fd): bool
    {
        return $fd >= self::VIRTUAL_FD_BASE;
    }

    /**
     * 写入出站队列并唤醒 long-poll。
     */
    public static function enqueueOutbound(string $sid, string $packet): bool
    {
        if ($sid === '' || $packet === '') {
            return false;
        }

        self::$outbound[$sid] ??= [];
        self::$outbound[$sid][] = $packet;
        self::getWaitChannel($sid)->push(true);

        return true;
    }

    /**
     * long-poll 等待出站包，超时返回空数组。
     *
     * @return string[]
     */
    public static function waitOutbound(string $sid, int $timeoutSec): array
    {
        $packets = self::drainOutbound($sid);
        if ($packets !== []) {
            return $packets;
        }

        if ($timeoutSec <= 0) {
            return [];
        }

        self::getWaitChannel($sid)->pop((float) $timeoutSec);

        return self::drainOutbound($sid);
    }

    /**
     * @return string[]
     */
    public static function drainOutbound(string $sid): array
    {
        if (!isset(self::$outbound[$sid]) || self::$outbound[$sid] === []) {
            return [];
        }

        $packets = self::$outbound[$sid];
        self::$outbound[$sid] = [];

        return $packets;
    }

    public static function destroySession(string $sid): void
    {
        unset(self::$outbound[$sid], self::$waitChannels[$sid]);

        if (isset(self::$sidToVirtualFd[$sid])) {
            $virtualFd = self::$sidToVirtualFd[$sid];
            unset(self::$sidToVirtualFd[$sid], self::$virtualFdToSid[$virtualFd]);
        }
    }

    public static function destroyVirtualFd(int $virtualFd): void
    {
        $sid = self::getSidByVirtualFd($virtualFd);
        if ($sid !== '') {
            self::destroySession($sid);
        }
    }

    /** 单测清理 */
    public static function resetForTest(): void
    {
        self::$outbound = [];
        self::$waitChannels = [];
        self::$sidToVirtualFd = [];
        self::$virtualFdToSid = [];
        self::$nextVirtualFd = self::VIRTUAL_FD_BASE;
    }

    private static function getWaitChannel(string $sid): Channel
    {
        if (!isset(self::$waitChannels[$sid])) {
            self::$waitChannels[$sid] = new Channel(8);
        }

        return self::$waitChannels[$sid];
    }
}

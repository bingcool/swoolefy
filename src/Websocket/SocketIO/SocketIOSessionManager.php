<?php

namespace Swoolefy\Websocket\SocketIO;

use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\Cluster\ClusterNodeIdentity;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingConfig;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingOutboundStore;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingSessionRegistry;
use Swoolefy\Websocket\SocketIO\Polling\SocketIOPollingWaitCoordinator;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * Socket.IO polling 会话（sid → 虚拟 fd + 出站队列 + long-poll 唤醒）。
 *
 * ## 存储模式（socketio.polling.shared_store）
 *
 * | 模式 | sid 索引 | 出站队列 | 适用 |
 * |------|----------|----------|------|
 * | memory | 进程内存 | 进程内存 + Channel | 单 Worker 开发 |
 * | redis（auto） | Swoole Table + Redis | Redis List + BRPOP | 多 Worker / 集群生产 |
 *
 * 多 Worker 下 polling 握手与 long-poll 可落在不同 Worker，共享存储保证 sid 可解析、push 可跨 Worker 送达。
 */
class SocketIOSessionManager
{
    private const VIRTUAL_FD_BASE = 0x40000000;

    public const TABLE_POLLING_META = 'table_websocket_polling_meta';

    /** @var array<string, int> memory 模式：sid → virtual_fd */
    private static array $sidToVirtualFd = [];

    /** @var array<int, string> memory 模式：virtual_fd → sid */
    private static array $virtualFdToSid = [];

    private static int $nextVirtualFd = self::VIRTUAL_FD_BASE;

    public static function allocateVirtualFd(): int
    {
        if (SocketIOPollingConfig::usesSharedStore()
            && TableManager::isExistTable(self::TABLE_POLLING_META)) {
            $seq = TableManager::incr(self::TABLE_POLLING_META, 'global', 'seq');

            return self::VIRTUAL_FD_BASE + $seq;
        }

        return self::$nextVirtualFd++;
    }

    public static function bindSid(string $sid, int $virtualFd, string $connId = ''): void
    {
        if ($sid === '' || $virtualFd <= 0) {
            return;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            self::$sidToVirtualFd[$sid] = $virtualFd;
            self::$virtualFdToSid[$virtualFd] = $sid;

            return;
        }

        if ($connId === '') {
            $connId = ClusterNodeIdentity::makeConnId($virtualFd);
        }

        SocketIOPollingSessionRegistry::register($sid, $virtualFd, $connId);
    }

    public static function hasSession(string $sid): bool
    {
        if ($sid === '') {
            return false;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            return isset(self::$sidToVirtualFd[$sid]);
        }

        return SocketIOPollingSessionRegistry::exists($sid);
    }

    public static function getVirtualFd(string $sid): int
    {
        if ($sid === '') {
            return 0;
        }

        if (!SocketIOPollingConfig::usesSharedStore()) {
            return (int) (self::$sidToVirtualFd[$sid] ?? 0);
        }

        return SocketIOPollingSessionRegistry::getVirtualFd($sid);
    }

    public static function getSidByVirtualFd(int $virtualFd): string
    {
        if (!SocketIOPollingConfig::usesSharedStore()) {
            return (string) (self::$virtualFdToSid[$virtualFd] ?? '');
        }

        $connection = WebsocketConnectionManager::getConnection($virtualFd);

        return (string) ($connection['sid'] ?? '');
    }

    public static function isVirtualFd(int $fd): bool
    {
        return $fd >= self::VIRTUAL_FD_BASE;
    }

    public static function touchSession(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        if (SocketIOPollingConfig::usesSharedStore()) {
            SocketIOPollingSessionRegistry::touch($sid);
        }
    }

    /**
     * 写入出站队列并唤醒 long-poll。
     */
    public static function enqueueOutbound(string $sid, string $packet): bool
    {
        return SocketIOPollingOutboundStore::enqueue($sid, $packet);
    }

    /**
     * long-poll GET：先 drain；空则「单 sid 单 waiter + 短 BRPOP」，其余 GET 立即空响应。
     *
     * - 短阻塞（默认 2s）：有 push/ping 时低延迟，且 Worker 可快速释放处理 POST `40`
     * - 单 waiter：避免并发 GET 占满 Worker 导致 connecting 卡住
     * - 未获锁的 GET 空 body：合法，客户端立即重 poll，QPS 可控（非 busy-loop 25s）
     *
     * @return string[]
     */
    public static function waitOutbound(string $sid, int $timeoutSec): array
    {
        $packets = SocketIOPollingOutboundStore::drain($sid);
        if ($packets !== []) {
            return $packets;
        }

        $waitSec = SocketIOPollingConfig::shortPollWaitSec($timeoutSec);
        if ($waitSec <= 0) {
            return [];
        }

        if (!SocketIOPollingWaitCoordinator::tryAcquire($sid, $waitSec)) {
            return [];
        }

        try {
            $item = SocketIOPollingOutboundStore::blockingPop($sid, $waitSec);
            if ($item === null) {
                return [];
            }

            return array_merge([$item], SocketIOPollingOutboundStore::drain($sid));
        } finally {
            SocketIOPollingWaitCoordinator::release($sid);
        }
    }

    /**
     * @return string[]
     */
    public static function drainOutbound(string $sid): array
    {
        return SocketIOPollingOutboundStore::drain($sid);
    }

    public static function destroySession(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        SocketIOPollingOutboundStore::clear($sid);
        SocketIOPollingWaitCoordinator::release($sid);

        if (SocketIOPollingConfig::usesSharedStore()) {
            SocketIOPollingSessionRegistry::remove($sid);

            return;
        }

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
        self::$sidToVirtualFd = [];
        self::$virtualFdToSid = [];
        self::$nextVirtualFd = self::VIRTUAL_FD_BASE;
        SocketIOPollingOutboundStore::resetForTest();
        SocketIOPollingSessionRegistry::resetForTest();
        SocketIOPollingWaitCoordinator::resetForTest();
        SocketIOPollingConfig::setSharedStoreOverrideForTest(null);
    }
}

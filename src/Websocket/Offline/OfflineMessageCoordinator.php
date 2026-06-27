<?php

namespace Swoolefy\Websocket\Offline;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 离线消息协调器（框架核心，业务一般无需继承）。
 *
 * ## 完整链路
 *
 * ```
 * [发送] pushToUser / ExternalPushPublisher::pushToUser
 *          ↓ 查 user 索引，deliveredCount=0
 *        maybeStoreOffline() → OfflineMessageStoreInterface::store()
 *
 * [上线] WebsocketConnectionManager::open() / bindUser()
 *          ↓ user_id 非空
 *        onUserOnline()
 *          ├─ replay_on_reconnect ? replayToFd() → deliverEventToFdLocally + markDelivered
 *          └─ offline.on_reconnect 钩子
 *
 * [拉取] pullOfflineMessages / HTTP pullPending
 *          ↓ 客户端展示后
 *        ackDelivered() → markDelivered
 * ```
 *
 * ## 配置项（Config/websocket.php → offline）
 *
 * | 键 | 说明 |
 * |----|------|
 * | enable | 总开关，且 store 必须可解析 |
 * | store | OfflineMessageStoreInterface 实现 |
 * | events | 允许落库的事件白名单；`[]` 或 `*` = 全部 pushToUser |
 * | replay_on_reconnect | 上线是否自动补推（默认 true） |
 * | on_reconnect | 补推完成后的业务钩子 |
 * | replay_limit | 单次补推/拉取上限（默认 100，最大 500） |
 *
 * @see OfflineMessageStoreInterface
 * @see OfflineReconnectHookInterface
 */
class OfflineMessageCoordinator
{
    /**
     * pushToUser 投递后的离线落库判断。
     *
     * **关键点**：以 deliveredCount 为准，而非「用户是否存在索引」——
     * 索引过期/多端全离线时 count=0，此时才写入离线表。
     *
     * 集成点：
     * - WebsocketConnectionManager::pushEventToUser
     * - ExternalPushPublisher::pushToUser
     *
     * @return string|null 离线消息 id；未存储时 null
     */
    public static function maybeStoreOffline(string $userId, string $event, $data, int $deliveredCount): ?string
    {
        // 已在线送达 / 功能未开 / 事件不在白名单 → 不落库
        if (!self::isEnabled() || $deliveredCount > 0 || !self::shouldStoreEvent($event)) {
            return null;
        }

        $store = OfflineMessageStoreFactory::get();
        if (!$store instanceof OfflineMessageStoreInterface) {
            return null;
        }

        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        // 落库时带上 trace_id，补推时可恢复链路
        return $store->store($userId, $event, $data, [
            'trace_id' => WebsocketTraceContext::currentOrNew(),
            'source' => 'pushToUser',
            'created_at' => time(),
        ]);
    }

    /**
     * 用户上线（重连）入口：补推 + on_reconnect 钩子。
     *
     * 触发时机（WebsocketConnectionManager）：
     * - open 完成且握手已绑定 user_id
     * - bindUser 换绑到新 user（含匿名 → 登录）
     *
     * @return int 框架默认补推成功条数
     */
    public static function onUserOnline(Server $server, int $fd, string $userId): int
    {
        $userId = trim($userId);
        if ($userId === '' || !self::isEnabled()) {
            return 0;
        }

        $replayed = 0;
        if (self::replayOnReconnect()) {
            $replayed = self::replayToFd($server, $fd, $userId, self::replayLimit());
        }

        // 钩子始终在补推之后，便于业务基于 replayedCount 做增量逻辑
        $hook = OfflineReconnectHookFactory::get();
        if ($hook instanceof OfflineReconnectHookInterface) {
            try {
                $hook->onReconnect($server, $fd, $userId, $replayed);
            } catch (\Throwable $throwable) {
                // 钩子异常不影响连接建立
            }
        }

        return $replayed;
    }

    /**
     * 拉取待投递离线消息（HTTP / WS 拉取模式）。
     *
     * 与补推模式二选一或并存：客户端可拒绝自动 push，改为主动 pull + ack。
     *
     * @return array{messages: array<int, array>, next_after_id: string, pending_total: int}
     */
    public static function pullPending(string $userId, int $limit = 50, ?string $afterId = null): array
    {
        $userId = trim($userId);
        if ($userId === '' || !self::isEnabled()) {
            return ['messages' => [], 'next_after_id' => '', 'pending_total' => 0];
        }

        $store = OfflineMessageStoreFactory::get();
        if (!$store instanceof OfflineMessageStoreInterface) {
            return ['messages' => [], 'next_after_id' => '', 'pending_total' => 0];
        }

        $limit = max(1, min(self::replayLimit(), $limit));
        $messages = $store->fetchPending($userId, $limit, $afterId);
        // next_after_id 供客户端分页：下次请求传 afterId=本页最后一条 id
        $nextAfterId = $messages !== [] ? (string) end($messages)['id'] : (string) ($afterId ?? '');

        return [
            'messages' => $messages,
            'next_after_id' => $nextAfterId,
            'pending_total' => $store->countPending($userId),
        ];
    }

    /** 拉取模式 ACK：客户端确认已展示/处理的消息 id 列表 */
    public static function ackDelivered(string $userId, array $messageIds): int
    {
        $userId = trim($userId);
        if ($userId === '' || !self::isEnabled() || $messageIds === []) {
            return 0;
        }

        $store = OfflineMessageStoreFactory::get();
        if (!$store instanceof OfflineMessageStoreInterface) {
            return 0;
        }

        return $store->markDelivered($userId, $messageIds);
    }

    public static function countPending(string $userId): int
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $store = OfflineMessageStoreFactory::get();

        return $store instanceof OfflineMessageStoreInterface ? $store->countPending(trim($userId)) : 0;
    }

    /** enable=true 且 store 可解析时才视为开启（防止只开开关未实现存储） */
    public static function isEnabled(): bool
    {
        $offline = ClusterConfig::offlineSettings();

        return !empty($offline['enable']) && OfflineMessageStoreFactory::get() instanceof OfflineMessageStoreInterface;
    }

    /**
     * 事件白名单：offline.events 为空数组时允许所有 pushToUser 落库。
     *
     * @internal
     */
    public static function shouldStoreEvent(string $event): bool
    {
        $events = ClusterConfig::offlineSettings()['events'] ?? null;
        if (!is_array($events) || $events === []) {
            return true;
        }

        if (in_array('*', $events, true)) {
            return true;
        }

        return in_array($event, $events, true);
    }

    private static function replayOnReconnect(): bool
    {
        $offline = ClusterConfig::offlineSettings();
        if (array_key_exists('replay_on_reconnect', $offline)) {
            return (bool) $offline['replay_on_reconnect'];
        }

        return true;
    }

    private static function replayLimit(): int
    {
        return max(1, min(500, (int) (ClusterConfig::offlineSettings()['replay_limit'] ?? 100)));
    }

    /**
     * 上线补推：逐条 deliverEventToFdLocally（走 enricher），成功则 markDelivered。
     *
     * **注意**：
     * - `skipped`（enricher 返回 null，如消息已删）也视为已处理，避免 PEL 式重复补推
     * - `failed` / `gone` 不 mark，下次上线重试
     */
    private static function replayToFd(Server $server, int $fd, string $userId, int $limit): int
    {
        $store = OfflineMessageStoreFactory::get();
        if (!$store instanceof OfflineMessageStoreInterface) {
            return 0;
        }

        $messages = $store->fetchPending($userId, $limit);
        if ($messages === []) {
            return 0;
        }

        $deliveredIds = [];
        foreach ($messages as $message) {
            $event = (string) ($message['event'] ?? '');
            $data = is_array($message['data'] ?? null) ? $message['data'] : [];
            $traceId = trim((string) ($message['trace_id'] ?? ''));
            if ($traceId !== '') {
                WebsocketTraceContext::apply($traceId);
            }

            $outcome = WebsocketConnectionManager::deliverEventToFdLocallyDetailed($server, $fd, $event, $data);
            if ($outcome === 'delivered' || $outcome === 'skipped') {
                $deliveredIds[] = (string) ($message['id'] ?? '');
            }
        }

        if ($deliveredIds !== []) {
            $store->markDelivered($userId, $deliveredIds);
        }

        return count($deliveredIds);
    }
}

<?php

namespace Swoolefy\Websocket\Offline;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushFanoutResult;
use Swoolefy\Websocket\Cluster\PushMessage;
use Swoolefy\Websocket\Cluster\RedisConnectionRegistry;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 离线消息协调器（框架核心，业务一般无需继承）。
 *
 * ## 完整链路
 *
 * ```
 * [发送] pushToUser / pushToGroup / broadcast
 *          ↓ targetCount=0（索引无连接/无在线节点）
 *        maybeStoreOfflineAfter*Push() → 读 data.offline_user_ids 落库
 *          ↓ 有连接但 Redis 索引过期（僵尸 conn）
 *        maybeStoreOfflineAfterDelivery() → 按 user_id 聚合 gone 落库
 *        store() → OfflineMessageStoreInterface
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
 * ## 群/广播离线要点
 *
 * - Redis 只维护**在线** conn 索引，完整群成员由业务在 `data.offline_user_ids` 中传入
 * - 推送阶段：`PushFanoutResult::targetCount === 0` 才落库（有在线连接则等投递结果）
 * - 投递阶段：同一 user 多 fd 时，任一 fd `delivered` 即视为已送达，不再落库
 * - `failed` / `serverUnavailable` 时不落库：前者可能临时故障需 PEL 重试，后者消费进程未就绪
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
     * pushToUser 投递后的离线落库判断（兼容旧调用：仅 deliveredCount=0）。
     *
     * 集群模式请优先使用 {@see maybeStoreOfflineAfterPush()}。
     *
     * @return string|null 离线消息 id；未存储时 null
     */
    public static function maybeStoreOffline(string $userId, string $event, $data, int $deliveredCount): ?string
    {
        if ($deliveredCount > 0) {
            return null;
        }

        return self::storeOfflineMessage($userId, $event, $data, 'pushToUser');
    }

    /**
     * 扇出后离线落库：仅当 Redis 索引中无任何可路由连接时写入。
     */
    public static function maybeStoreOfflineAfterPush(
        string $userId,
        string $event,
        $data,
        PushFanoutResult $result
    ): ?string {
        if (!$result->shouldStoreOfflineAtPush()) {
            return null;
        }

        return self::storeOfflineMessage($userId, $event, $data, 'pushToUser');
    }

    /**
     * pushToGroup 扇出后离线落库（小组内无在线连接时）。
     *
     * 业务可在 $data['offline_user_ids'] 中传入离线成员 user_id 列表。
     *
     * @return int 写入条数
     */
    public static function maybeStoreOfflineAfterGroupPush(
        string $group,
        string $event,
        $data,
        PushFanoutResult $result
    ): int {
        if (!$result->shouldStoreOfflineAtPush()) {
            return 0;
        }

        return self::storeOfflineForUserIds(
            self::resolveOfflineUserIdsAtPush($data, $group),
            $event,
            $data,
            'pushToGroup'
        );
    }

    /**
     * broadcast 扇出后离线落库（集群无在线节点时）。
     *
     * 业务可在 $data['offline_user_ids'] 中传入需必达的用户列表。
     *
     * @return int 写入条数
     */
    public static function maybeStoreOfflineAfterBroadcastPush(string $event, $data, PushFanoutResult $result): int
    {
        if (!$result->shouldStoreOfflineAtPush()) {
            return 0;
        }

        return self::storeOfflineForUserIds(
            self::resolveOfflineUserIdsAtPush($data, ''),
            $event,
            $data,
            'broadcast'
        );
    }

    /**
     * 单机 pushToGroup / broadcast 按 user 聚合投递结果后落库。
     *
     * @param array<string, string[]> $userOutcomes user_id => outcome 列表
     *
     * @return int 写入条数
     */
    public static function maybeStoreOfflineAfterLocalFanout(string $event, $data, array $userOutcomes, string $source): int
    {
        return self::storeOfflineForUserOutcomes($event, $data, $userOutcomes, $source);
    }

    /**
     * Stream/本机投递后离线回补：按 user_id 聚合，未送达且 gone 的用户写入离线表。
     *
     * 与推送阶段互补：索引命中但 fd 已断开（僵尸 conn）时在此落库。
     * failed/serverUnavailable 跳过：与 Streams PEL 重试策略一致，避免重复落库。
     */
    public static function maybeStoreOfflineAfterDelivery(array $message, PushDeliveryResult $result): int
    {
        // failed：fd 在线但 push 失败，PEL 会重试，不应提前写离线表
        // serverUnavailable：Worker 未就绪，留 PEL 重投后再判
        if ($result->failed > 0 || $result->serverUnavailable || $result->duplicateSkipped || $result->invalidPayload) {
            return 0;
        }

        $event = (string) ($message['event'] ?? '');
        if ($event === '' || !self::shouldStoreEvent($event)) {
            return 0;
        }

        $data = $message['data'] ?? [];
        $traceId = trim((string) ($message['trace_id'] ?? ''));
        if ($traceId !== '') {
            WebsocketTraceContext::apply($traceId);
        }

        return self::storeOfflineForUserOutcomes(
            $event,
            $data,
            self::aggregateOutcomesByUser($message, $result),
            'deliveryGone'
        );
    }

    private static function storeOfflineMessage(string $userId, string $event, $data, string $source): ?string
    {
        if (!self::isEnabled() || !self::shouldStoreEvent($event)) {
            return null;
        }

        $store = OfflineMessageStoreFactory::get();
        if (!$store instanceof OfflineMessageStoreInterface) {
            return null;
        }

        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        if (!is_array($data)) {
            $data = ['value' => $data];
        }

        return $store->store($userId, $event, $data, [
            'trace_id' => WebsocketTraceContext::currentOrNew(),
            'source' => $source,
            'created_at' => time(),
        ]);
    }

    /**
     * @param string[] $userIds
     */
    private static function storeOfflineForUserIds(array $userIds, string $event, $data, string $source): int
    {
        $stored = 0;
        foreach (array_values(array_unique(array_filter($userIds, static fn (string $id): bool => trim($id) !== ''))) as $userId) {
            if (self::storeOfflineMessage($userId, $event, $data, $source) !== null) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * @param array<string, string[]> $userOutcomes
     */
    private static function storeOfflineForUserOutcomes(string $event, $data, array $userOutcomes, string $source): int
    {
        if (!self::isEnabled() || !self::shouldStoreEvent($event)) {
            return 0;
        }

        $stored = 0;
        foreach ($userOutcomes as $userId => $outcomes) {
            if (!is_array($outcomes) || !self::shouldStoreOfflineForOutcomes($outcomes)) {
                continue;
            }

            if (self::storeOfflineMessage((string) $userId, $event, $data, $source) !== null) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * 多 fd / 多 outcome 聚合后的落库判定。
     *
     * 规则：有 delivered → 已送达；有 failed → 等 PEL 重试；仅 gone → 写离线表。
     *
     * @param string[] $outcomes
     */
    private static function shouldStoreOfflineForOutcomes(array $outcomes): bool
    {
        if ($outcomes === []) {
            return false;
        }

        if (in_array('delivered', $outcomes, true)) {
            return false;
        }

        if (in_array('failed', $outcomes, true)) {
            return false;
        }

        return in_array('gone', $outcomes, true);
    }

    /**
     * 推送阶段解析离线用户列表。
     *
     * 框架 Redis 无群成员表，targetCount=0 时只能依赖业务传入 offline_user_ids。
     *
     * @return string[]
     */
    private static function resolveOfflineUserIdsAtPush($data, string $group): array
    {
        $userIds = self::extractOfflineUserIds($data);
        if ($userIds !== []) {
            return $userIds;
        }

        unset($group);

        return [];
    }

    /**
     * 从 push data 解析业务传入的离线用户列表。
     *
     * @return string[]
     */
    private static function extractOfflineUserIds($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $ids = $data['offline_user_ids'] ?? [];
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids
        ), static fn (string $id): bool => $id !== '')));
    }

    /**
     * 将 PushDeliveryResult.targetDetails 按 user_id 分组。
     *
     * group/broadcast 一次推送含多个 fd，必须按用户聚合后再判是否落库。
     * 无 targetDetails 时回退 recipient_user_id（pushToUser 单用户场景）。
     *
     * @return array<string, string[]>
     */
    private static function aggregateOutcomesByUser(array $message, PushDeliveryResult $result): array
    {
        $userOutcomes = [];
        foreach ($result->targetDetails as $detail) {
            $userId = trim((string) ($detail['user_id'] ?? ''));
            if ($userId === '') {
                continue;
            }

            $userOutcomes[$userId][] = (string) ($detail['outcome'] ?? '');
        }

        if ($userOutcomes !== []) {
            return $userOutcomes;
        }

        if ($result->delivered > 0 || $result->failed > 0 || $result->gone === 0) {
            return [];
        }

        $userId = self::resolveRecipientUserId($message);
        if ($userId !== '') {
            return [$userId => ['gone']];
        }

        return [];
    }

    private static function resolveRecipientUserId(array $message): string
    {
        $userId = trim((string) ($message['recipient_user_id'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        $data = is_array($message['data'] ?? null) ? $message['data'] : [];
        $userId = trim((string) ($data['to_user_id'] ?? ''));
        if ($userId !== '') {
            return $userId;
        }

        $targets = is_array($message['targets'] ?? null) ? $message['targets'] : [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $connId = (string) ($target['conn_id'] ?? '');
            if ($connId === '') {
                continue;
            }

            $meta = RedisConnectionRegistry::getConnectionMeta($connId);
            if (!is_array($meta)) {
                continue;
            }

            $userId = trim((string) ($meta['user_id'] ?? ''));
            if ($userId !== '') {
                return $userId;
            }
        }

        return '';
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

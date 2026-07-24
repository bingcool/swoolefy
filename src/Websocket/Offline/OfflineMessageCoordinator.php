<?php

namespace Swoolefy\Websocket\Offline;

use Swoole\WebSocket\Server;
use Swoolefy\Websocket\Cluster\ClusterConfig;
use Swoolefy\Websocket\Cluster\PushDeliveryResult;
use Swoolefy\Websocket\Cluster\PushFanoutResult;
use Swoolefy\Websocket\Cluster\RedisConnectionRegistry;
use Swoolefy\Websocket\Metrics\WebsocketTraceContext;
use Swoolefy\Websocket\WebsocketConnectionManager;

/**
 * 离线消息协调器（框架核心，业务一般无需继承或直接改本类）。
 *
 * ## 职责边界
 *
 * | 角色 | 负责 |
 * |------|------|
 * | 本类 | 判断「何时落库 / 何时补推 / 何时 ACK」，调度 Store 与钩子 |
 * | {@see OfflineMessageStoreInterface} | 真正的持久化（MySQL / Redis 等由业务实现） |
 * | {@see OfflineReconnectHookInterface} | 上线补推后的业务回调（可选） |
 *
 * 框架**不内置** MySQL 实现；通过 `Config/websocket.php → offline.store` 注入业务 Store。
 *
 * ## 完整链路
 *
 * ```
 * [发送] pushToUser / pushToGroup / broadcast
 *          ↓ 扇出阶段：索引无任何可路由连接（targetCount=0）
 *        maybeStoreOfflineAfter*Push()
 *          · pushToUser → 直接按 userId 落库
 *          · group/broadcast → 读 data.offline_user_ids 批量落库
 *          ↓ 投递阶段：索引命中但 fd 已断（僵尸 conn → outcome=gone）
 *        maybeStoreOfflineAfterDelivery() → 按 user_id 聚合后落库
 *        store() → OfflineMessageStoreInterface::store()
 *
 * [上线] WebsocketConnectionManager::open() / bindUser()
 *          ↓ user_id 非空且 offline.enable + store 可解析
 *        onUserOnline()
 *          ├─ replay_on_reconnect=true → replayToFd()
 *          │     deliverEventToFdLocallyDetailed + markDelivered
 *          └─ offline.on_reconnect 钩子（始终在补推之后）
 *
 * [拉取] HTTP / WS 主动拉
 *        pullPending() → 客户端展示
 *        ackDelivered() → markDelivered
 * ```
 *
 * ## 两阶段落库（为何分「推送」和「投递」）
 *
 * 1. **推送阶段（Fanout）**  
 *    Redis 只知道「当前在线 conn」。若 `targetCount=0`，说明此刻没有任何可路由连接，
 *    对单用户可直接落库；对群/广播则必须由业务在 `data.offline_user_ids` 给出离线成员列表
 *    （框架没有完整群成员表）。
 *
 * 2. **投递阶段（Delivery）**  
 *    索引里有 conn，但真正 `server->push` 时 fd 已断开（僵尸索引）→ outcome=`gone`。
 *    此时再按 **user_id** 聚合：同一用户多端任一 `delivered` 即视为已送达，不再落库；
 *    全部为 `gone` 才写离线表。
 *
 * ## 不落库的情况（刻意跳过）
 *
 * | 结果 | 原因 |
 * |------|------|
 * | `failed` | fd 仍在，push 临时失败；交给 Streams PEL 重试，避免与重投重复落库 |
 * | `serverUnavailable` | 消费 Worker 未就绪，留 PEL 重投后再判 |
 * | `duplicateSkipped` / `invalidPayload` | 无效或重复消息，不写离线 |
 * | 事件不在 `offline.events` 白名单 | 业务只想对部分事件做离线必达 |
 *
 * ## 配置项（Config/websocket.php → offline）
 *
 * ```php
 * 'offline' => [
 *     'enable' => true,
 *     // 类名 / [Class::class] / [Factory::class, 'make'] / 闭包
 *     'store' => [\App\Offline\MysqlOfflineMessageStore::class],
 *     'events' => ['chat.private', 'notify.message'], // [] 或含 '*' = 全部事件
 *     'replay_on_reconnect' => true,  // 上线自动补推；false 则仅走钩子/拉取
 *     'on_reconnect' => [\App\Offline\OfflineReconnectCallback::class, 'onReconnect'],
 *     'replay_limit' => 100,          // 单次补推/拉取上限，最大 500
 * ],
 * ```
 *
 * ## 业务接入要点
 *
 * - 群/广播必达：推送时带上 `'offline_user_ids' => ['u1', 'u2']`
 * - Store 必须实现 store / fetchPending / markDelivered / countPending
 * - `enable=true` 但 store 解析失败时 {@see isEnabled()} 为 false（防止空转）
 *
 * @see OfflineMessageStoreInterface
 * @see OfflineMessageStoreFactory
 * @see OfflineReconnectHookInterface
 * @see InMemoryOfflineMessageStore 单测 / 本地开发用内存实现
 */
class OfflineMessageCoordinator
{
    /**
     * pushToUser 投递后的离线落库（兼容旧签名：仅看 deliveredCount）。
     *
     * - `$deliveredCount > 0`：至少有一个 fd 收到，不落库
     * - `$deliveredCount === 0`：视为全离线，走 {@see storeOfflineMessage()}
     *
     * 集群模式请优先使用 {@see maybeStoreOfflineAfterPush()}（能区分「无索引」与「投递 gone」）。
     *
     * @param string $userId         目标用户
     * @param string $event          推送事件名（受 events 白名单约束）
     * @param mixed  $data           推送载荷；非数组会被包成 `['value' => $data]`
     * @param int    $deliveredCount 本机/旧路径统计的成功投递数
     *
     * @return string|null 离线消息主键；未存储时 null
     */
    public static function maybeStoreOffline(string $userId, string $event, $data, int $deliveredCount): ?string
    {
        if ($deliveredCount > 0) {
            return null;
        }

        return self::storeOfflineMessage($userId, $event, $data, 'pushToUser');
    }

    /**
     * 单用户扇出后的离线落库（推送阶段）。
     *
     * 仅当 {@see PushFanoutResult::shouldStoreOfflineAtPush()} 为 true
     * （通常即 targetCount=0：Redis 索引中无任何可路由连接）时写入。
     * 有在线连接时不在此落库，改由投递阶段 {@see maybeStoreOfflineAfterDelivery()} 处理僵尸 conn。
     *
     * @return string|null 离线消息 id；未存储时 null
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
     * 群推送扇出后的离线落库（推送阶段：组内无在线连接）。
     *
     * 框架 Redis **不维护**完整群成员，只能依赖业务在 `$data['offline_user_ids']` 传入离线成员。
     * 若未传列表，本方法写入条数为 0（不会猜测群成员）。
     *
     * @param string           $group  群名（当前仅占位，解析用户列表不依赖它）
     * @param PushFanoutResult $result 扇出结果；targetCount>0 时直接返回 0
     *
     * @return int 成功写入的离线条数（按 user 计）
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
     * 广播扇出后的离线落库（推送阶段：集群无任何在线节点可路由）。
     *
     * 与群推送相同：必须由业务提供 `$data['offline_user_ids']` 表示「需要必达」的用户。
     *
     * @return int 成功写入的离线条数
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
     * 单机 pushToGroup / broadcast：按 user 聚合本机投递结果后落库。
     *
     * 用于非集群或本机 fanout 路径；语义同 {@see storeOfflineForUserOutcomes()}。
     *
     * @param array<string, string[]> $userOutcomes user_id => outcome 列表（delivered/gone/failed…）
     * @param string                  $source       写入 meta.source，便于排查来源
     *
     * @return int 写入条数
     */
    public static function maybeStoreOfflineAfterLocalFanout(string $event, $data, array $userOutcomes, string $source): int
    {
        return self::storeOfflineForUserOutcomes($event, $data, $userOutcomes, $source);
    }

    /**
     * Stream / 本机投递完成后的离线回补（投递阶段）。
     *
     * 与推送阶段互补：索引命中但 fd 已断开（僵尸 conn，outcome=gone）时在此落库。
     *
     * 以下情况直接返回 0，不写离线表：
     * - failed：fd 在线但 push 失败 → PEL 会重试，避免重复落库
     * - serverUnavailable：Worker 未就绪 → 留 PEL 重投后再判
     * - duplicateSkipped / invalidPayload：无效消息
     *
     * @param array              $message 集群推送消息体（含 event、data、trace_id、targets 等）
     * @param PushDeliveryResult $result  本节点投递统计与 targetDetails
     *
     * @return int 写入条数
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
            // 恢复推送时的 trace，保证落库 meta 与日志链路一致
            WebsocketTraceContext::apply($traceId);
        }

        return self::storeOfflineForUserOutcomes(
            $event,
            $data,
            self::aggregateOutcomesByUser($message, $result),
            'deliveryGone'
        );
    }

    /**
     * 真正调用 Store 写入单条离线消息。
     *
     * 前置条件：isEnabled、事件白名单、userId 非空、Store 可解析。
     * meta 固定带上 trace_id / source / created_at，供补推恢复链路与排查。
     *
     * @param string $source 来源标记：pushToUser | pushToGroup | broadcast | deliveryGone 等
     *
     * @return string|null Store 返回的消息主键；未写入时 null
     */
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
     * 按用户列表批量落库（群/广播推送阶段）。
     *
     * @param string[] $userIds 已去重、去空的用户 id
     *
     * @return int 成功写入条数（store 返回非 null 计 1）
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
     * 按「用户 → 多端 outcome 列表」决定是否落库并写入。
     *
     * @param array<string, string[]> $userOutcomes
     *
     * @return int 写入条数
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
     * 规则（同一 user）：
     * - 含 `delivered` → 已有端收到，不落库
     * - 含 `failed` → 等 PEL 重试，不落库
     * - 仅含（或包含）`gone` 且无上述两种 → 写离线表
     * - 空列表 → 不落库
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
     * 当前实现只认 `$data['offline_user_ids']`；`$group` 预留扩展（例如将来查群成员服务）。
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
     * 支持标量 id 混排；自动 trim、去重、去空字符串。
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
     * 将 {@see PushDeliveryResult::$targetDetails} 按 user_id 分组。
     *
     * group/broadcast 一次推送含多个 fd，必须按用户聚合后再判是否落库。
     * 无 targetDetails 时回退 {@see resolveRecipientUserId()}（典型 pushToUser 单用户）。
     *
     * @return array<string, string[]> user_id => [outcome, ...]
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

        // 无明细时：仅「全部 gone」才回退成单用户 gone；有 delivered/failed 则不再猜用户
        if ($result->delivered > 0 || $result->failed > 0 || $result->gone === 0) {
            return [];
        }

        $userId = self::resolveRecipientUserId($message);
        if ($userId !== '') {
            return [$userId => ['gone']];
        }

        return [];
    }

    /**
     * 从消息体推断单用户收件人（投递明细缺失时的回退）。
     *
     * 优先级：
     * 1. message.recipient_user_id
     * 2. message.data.to_user_id
     * 3. message.targets[].conn_id → Redis 连接元数据中的 user_id
     */
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
     * 用户上线（重连）入口：可选自动补推 + on_reconnect 钩子。
     *
     * 触发时机（{@see WebsocketConnectionManager}）：
     * - open 完成且握手已绑定 user_id
     * - bindUser 换绑到新 user（含匿名 → 登录）
     *
     * 顺序固定：先 replay（若开启）→ 再调钩子；钩子异常被吞掉，不影响连接建立。
     * 若业务想完全自管补推：配 `replay_on_reconnect=false`，在钩子里自行 pullPending。
     *
     * @return int 框架默认补推并成功 mark 的条数（钩子可据此做增量）
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
     * 可与自动补推并存：客户端也可拒绝自动 push，改为主动 pull + {@see ackDelivered()}。
     * `limit` 会被钳制到 `[1, replay_limit]`。
     *
     * @param string|null $afterId 上一页最后一条消息 id；首页传 null
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

    /**
     * 拉取模式 ACK：客户端确认已展示/处理的消息 id 列表。
     *
     * @param array $messageIds Store 主键列表（与 fetchPending 返回的 id 对应）
     *
     * @return int Store::markDelivered 影响行数
     */
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

    /**
     * 待投递条数（角标、监控、pullPending.pending_total）。
     */
    public static function countPending(string $userId): int
    {
        if (!self::isEnabled()) {
            return 0;
        }

        $store = OfflineMessageStoreFactory::get();

        return $store instanceof OfflineMessageStoreInterface ? $store->countPending(trim($userId)) : 0;
    }

    /**
     * 离线能力是否真正可用。
     *
     * 条件：`offline.enable=true` **且** {@see OfflineMessageStoreFactory::get()} 能解析出 Store。
     * 只开开关、未配好 store 时返回 false，避免空转误以为已落库。
     */
    public static function isEnabled(): bool
    {
        $offline = ClusterConfig::offlineSettings();

        return !empty($offline['enable']) && OfflineMessageStoreFactory::get() instanceof OfflineMessageStoreInterface;
    }

    /**
     * 事件是否允许落库。
     *
     * - `events` 未配 / 空数组 → 全部允许
     * - 含 `'*'` → 全部允许
     * - 否则仅白名单内事件
     *
     * @internal 投递路径与单测也会调用
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

    /**
     * 是否在上线时自动补推。
     *
     * 未配置时默认 true；显式 `replay_on_reconnect=false` 则只走钩子/拉取。
     */
    private static function replayOnReconnect(): bool
    {
        $offline = ClusterConfig::offlineSettings();
        if (array_key_exists('replay_on_reconnect', $offline)) {
            return (bool) $offline['replay_on_reconnect'];
        }

        return true;
    }

    /**
     * 单次补推 / 拉取上限，钳制在 1～500。
     */
    private static function replayLimit(): int
    {
        return max(1, min(500, (int) (ClusterConfig::offlineSettings()['replay_limit'] ?? 100)));
    }

    /**
     * 上线补推：拉取 pending，逐条本机投递，成功则 markDelivered。
     *
     * 投递走 {@see WebsocketConnectionManager::deliverEventToFdLocallyDetailed()}，
     * 因此会经过 PushPayloadEnricher（引用模式可在此展开完整消息）。
     *
     * 结果处理：
     * - `delivered`：push 成功 → 加入待 ACK 列表
     * - `skipped`：enricher 返回 null（如消息已删）→ 也 mark，避免反复补推死信
     * - `failed` / `gone`：不 mark，等下次上线或拉取重试
     *
     * @return int 本轮 mark 的条数（含 skipped）
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
                // 用落库时的 trace 补推，便于日志串联
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

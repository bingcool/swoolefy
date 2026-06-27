<?php

namespace Swoolefy\Websocket\Cluster;

/**
 * Redis 全局连接注册表（跨 WebSocket 节点共享）。
 *
 * ## 职责
 *
 * 将各节点 Worker 进程内的 Swoole\Table 连接信息同步到 Redis，供集群推送时：
 *
 * - 按 **user_id** / **group** 查找目标 conn_id
 * - 按 **conn_id** 读取 fd、server_id、is_socketio 等投递元数据
 * - 按 **nodes** 集合做全集群 broadcast 扇出
 * - 通过 **alive** ZSET 清理节点宕机后遗留的僵尸索引
 *
 * 写操作由 `ClusterConnectionCoordinator` 在连接生命周期中调用；读操作由 `ClusterPushBus` 扇出时调用。
 *
 * ## Redis Key 布局（前缀默认 `ws:{APP_NAME}:`，见 ClusterConfig::keyPrefix()）
 *
 * | Key | 类型 | 说明 |
 * |-----|------|------|
 * | `{prefix}conn:{conn_id}` | Hash | 单连接元数据，带 TTL（conn_ttl） |
 * | `{prefix}user:{user_id}` | Set | 该用户下所有 conn_id（多端在线） |
 * | `{prefix}group:{group}` | Set | 该小组下所有 conn_id |
 * | `{prefix}node:{server_id}` | Set | 该节点下所有 conn_id |
 * | `{prefix}nodes` | Set | 当前有连接的在线节点 server_id 列表 |
 * | `{prefix}alive` | ZSET | member=conn_id，score=last_active_at，供过期清理 |
 *
 * **conn_id 格式**：`{server_id}:{fd}`（见 ClusterNodeIdentity），fd 仅在单节点内有效。
 *
 * **conn Hash 字段**：
 *
 * - `server_id` / `fd` / `worker_id`：路由与投递
 * - `user_id`：空字符串表示未绑定用户
 * - `groups`：JSON 数组字符串，如 `["room-a","room-b"]`
 * - `is_socketio`：0/1，投递时决定编码方式
 * - `remote_addr` / `connected_at` / `last_active_at`：审计与心跳
 *
 * ## 连接生命周期
 *
 * ```
 * onOpen   → register()     写 conn Hash + user/node/alive 索引
 * onBind   → bindUser()     更新 user 索引
 * onJoin   → joinGroup()    更新 group 索引
 * onLeave  → leaveGroup()   移除 group 索引
 * onTouch  → touch()        刷新 TTL 与 alive score（经 Coordinator 节流）
 * onClose  → unregister()   删除 conn 及全部反向索引
 * 定时任务 → cleanupExpired() 扫描 alive 超时 conn，等价于 unregister
 * ```
 *
 * ## 推送出口（publish / publishMany）
 *
 * 本类同时承担跨节点推送总线的 Redis 写入入口，按 `cluster.push.transport` 分流：
 *
 * - **streams（默认）**：`PushStreamPublisher` → XADD `{prefix}push:stream:{server_id}`
 * - **pubsub**：PUBLISH `ws:push:{APP}:{server_id}` 频道
 *
 * ## 与离线必达的关系
 *
 * 本注册表**只维护在线连接**的反向索引，不保存群成员全量列表：
 *
 * - `getConnIdsByUser` / `getConnIdsByGroup` 返回空 → 仅表示 Redis 中无在线 conn
 * - 离线用户列表需业务在 push `data.offline_user_ids` 中传入（见 OfflineMessageCoordinator）
 * - 索引中仍有 conn 但本地 fd 已断开 → **僵尸索引**，投递 gone 后由离线协调器回补
 *
 * ## 索引一致性与僵尸 conn
 *
 * - **双写**：本地 Swoole\Table 为权威状态；Redis 供跨节点查询，由 Coordinator 同步
 * - **TTL + alive ZSET**：conn Hash 带 EXPIRE；touch 刷新 score，cleanupExpired 扫超时 member
 * - **unregister 幂等**：Hash 已不存在时仍 zRem alive，避免重复 onClose 遗留 ZSET 条目
 * - **nodes 集合**：node 下 conn 清空时移除 server_id，防止 broadcast 扇出到已下线节点
 *
 * @see ClusterConnectionCoordinator  双写协调（Table + Redis）
 * @see ClusterPushBus                读索引并扇出推送
 * @see OfflineMessageCoordinator     索引为空 / gone 时的离线落库
 * @see ClusterConfig::keyPrefix()
 */
class RedisConnectionRegistry
{
    /**
     * 注册新连接：写入 conn Hash 并建立 user / node / alive 索引。
     *
     * 由 ClusterConnectionCoordinator::onOpen() 在 WebSocket 握手成功后调用。
     * conn Hash 设置 EXPIRE(conn_ttl)，防止 onClose 未触发时 key 永久残留。
     *
     * @param string $connId     全局连接 ID，格式 {server_id}:{fd}
     * @param array  $connection 来自本地 Table 的字段，需含 server_id、fd、user_id、groups 等
     */
    public static function register(string $connId, array $connection): void
    {
        self::execute(function ($redis) use ($connId, $connection) {
            $ttl = ClusterConfig::connTtl();
            $serverId = (string) $connection['server_id'];
            $userId = (string) ($connection['user_id'] ?? '');
            $lastActiveAt = (int) ($connection['last_active_at'] ?? time());

            $redis->hMSet(self::connKey($connId), [
                'server_id' => $serverId,
                'fd' => (int) ($connection['fd'] ?? 0),
                'worker_id' => (int) ($connection['worker_id'] ?? 0),
                'user_id' => $userId,
                'groups' => (string) ($connection['groups'] ?? ''),
                'is_socketio' => (int) ($connection['is_socketio'] ?? 0),
                'remote_addr' => (string) ($connection['remote_addr'] ?? ''),
                'connected_at' => (int) ($connection['connected_at'] ?? time()),
                'last_active_at' => $lastActiveAt,
            ]);
            $redis->expire(self::connKey($connId), $ttl);
            // 节点首次有连接时加入 nodes 集合，broadcast 据此枚举目标节点
            $redis->sAdd(self::nodesKey(), $serverId);
            $redis->sAdd(self::nodeConnsKey($serverId), $connId);
            // alive ZSET：worker 0 定时任务按 score 清理无 onClose 的僵尸索引（节点宕机场景）
            $redis->zAdd(self::aliveKey(), $lastActiveAt, $connId);

            if ($userId !== '') {
                // user Set 仅存 conn_id；pushToUser 先 SMEMBERS 再 getConnectionMetaMany pipeline
                $redis->sAdd(self::userKey($userId), $connId);
            }
        });
    }

    /**
     * 注销连接：删除 conn Hash 并清理 user / group / node / alive 全部反向索引。
     *
     * 若 conn Hash 已不存在（TTL 过期或重复 onClose），仅尝试从 alive 移除。
     * 节点 conn 集合为空时，从 nodes 集合移除该 server_id，避免向离线节点 broadcast。
     */
    public static function unregister(string $connId): void
    {
        self::execute(function ($redis) use ($connId) {
            $meta = $redis->hGetAll(self::connKey($connId));
            if (!is_array($meta) || empty($meta)) {
                // Hash 已被 TTL 清掉或重复 onClose：至少清理 alive，避免 ZSET 无限增长
                $redis->zRem(self::aliveKey(), $connId);

                return;
            }

            $serverId = (string) ($meta['server_id'] ?? '');
            $userId = (string) ($meta['user_id'] ?? '');
            $groups = self::decodeGroups((string) ($meta['groups'] ?? ''));

            $redis->del(self::connKey($connId));
            $redis->zRem(self::aliveKey(), $connId);

            if ($userId !== '') {
                $redis->sRem(self::userKey($userId), $connId);
            }

            foreach ($groups as $group) {
                $redis->sRem(self::groupKey($group), $connId);
            }

            if ($serverId !== '') {
                $redis->sRem(self::nodeConnsKey($serverId), $connId);
                // 节点下最后一个 conn 断开 → 从 nodes 移除，broadcast 不再扇出到此节点
                if ((int) $redis->sCard(self::nodeConnsKey($serverId)) === 0) {
                    $redis->sRem(self::nodesKey(), $serverId);
                }
            }
        });
    }

    /**
     * 绑定或切换用户：更新 conn Hash 的 user_id，并维护 user Set 索引。
     *
     * 匿名 → 登录、换号时由 Coordinator 调用；旧 user Set 必须 sRem，否则 pushToUser
     * 仍会扇出到已换绑用户的 conn_id。
     *
     * @param string $oldUserId 换绑前的 user_id，非空且与 $userId 不同时从旧 Set 移除
     */
    public static function bindUser(string $connId, string $userId, string $oldUserId = ''): void
    {
        self::execute(function ($redis) use ($connId, $userId, $oldUserId) {
            if ($oldUserId !== '' && $oldUserId !== $userId) {
                $redis->sRem(self::userKey($oldUserId), $connId);
            }

            $redis->hSet(self::connKey($connId), 'user_id', $userId);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());

            if ($userId !== '') {
                $redis->sAdd(self::userKey($userId), $connId);
            }
        });
    }

    /**
     * 加入小组：更新 conn Hash 的 groups JSON，并向 group Set 添加 conn_id。
     *
     * group Set 与 user Set 同理，只索引**当前在线** conn；离线群成员不在此维护。
     *
     * @param string $groupsJson 完整 groups 数组的 JSON，由调用方（Table）维护后传入
     */
    public static function joinGroup(string $connId, string $group, string $groupsJson): void
    {
        self::execute(function ($redis) use ($connId, $group, $groupsJson) {
            $redis->hSet(self::connKey($connId), 'groups', $groupsJson);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->sAdd(self::groupKey($group), $connId);
        });
    }

    /**
     * 离开小组：更新 groups JSON，并从 group Set 移除 conn_id。
     *
     * groupsJson 由本地 Table 维护完整列表后传入，Redis 不单独维护 group 成员表。
     */
    public static function leaveGroup(string $connId, string $group, string $groupsJson): void
    {
        self::execute(function ($redis) use ($connId, $group, $groupsJson) {
            $redis->hSet(self::connKey($connId), 'groups', $groupsJson);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->sRem(self::groupKey($group), $connId);
        });
    }

    /**
     * 刷新连接心跳：更新 last_active_at 字段、续期 conn TTL、更新 alive ZSET score。
     *
     * 实际调用频率由 ClusterConnectionCoordinator::onTouch() 按 touch_interval 节流。
     */
    public static function touch(string $connId, int $lastActiveAt): void
    {
        self::execute(function ($redis) use ($connId, $lastActiveAt) {
            $redis->hSet(self::connKey($connId), 'last_active_at', $lastActiveAt);
            $redis->expire(self::connKey($connId), ClusterConfig::connTtl());
            $redis->zAdd(self::aliveKey(), $lastActiveAt, $connId);
        });
    }

    /**
     * 查询用户下所有 conn_id（pushToUser 扇出的第一步）。
     *
     * 返回空数组 ≠ 用户不存在，仅表示当前无在线连接；离线落库走 push 阶段或 gone 回补。
     *
     * @return string[]
     */
    public static function getConnIdsByUser(string $userId): array
    {
        return self::execute(function ($redis) use ($userId) {
            $items = $redis->sMembers(self::userKey($userId));

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    /**
     * 查询小组下所有 conn_id（pushToGroup 扇出的第一步）。
     *
     * 返回空数组时 pushToGroup 无法在推送阶段送达，需业务传 data.offline_user_ids。
     *
     * @return string[]
     */
    public static function getConnIdsByGroup(string $group): array
    {
        return self::execute(function ($redis) use ($group) {
            $items = $redis->sMembers(self::groupKey($group));

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    /**
     * 读取单条连接元数据（Hash 全字段）。
     *
     * @return array<string, string>|null conn 不存在或已过期时返回 null
     */
    public static function getConnectionMeta(string $connId): ?array
    {
        $many = self::getConnectionMetaMany([$connId]);

        return $many[$connId] ?? null;
    }

    /**
     * Pipeline 批量读取 conn Hash，减少 pushToGroup / pushToUser 扇出时的 Redis 往返。
     *
     * ClusterPushBus::targetsFromConnIds() 在拿到 conn_id 列表后调用本方法，
     * 一次 pipeline 完成 N 次 HGETALL，过滤已过期（Hash 为空）的连接。
     *
     * @param string[] $connIds
     *
     * @return array<string, array<string, string>> conn_id => Hash 字段
     */
    public static function getConnectionMetaMany(array $connIds): array
    {
        $connIds = array_values(array_unique(array_filter($connIds, static function ($connId) {
            return is_string($connId) && $connId !== '';
        })));
        if ($connIds === []) {
            return [];
        }

        return self::execute(function ($redis) use ($connIds) {
            $keyToConnId = [];
            $keys = [];
            foreach ($connIds as $connId) {
                $key = self::connKey($connId);
                $keys[] = $key;
                $keyToConnId[$key] = $connId;
            }

            $rows = $redis->hGetAllMany($keys);
            $result = [];
            foreach ($rows as $key => $meta) {
                $connId = $keyToConnId[$key] ?? '';
                // Hash 为空 = TTL 过期或已 unregister；ClusterPushBus 会跳过，不当作有效 target
                if ($connId !== '' && is_array($meta) && $meta !== []) {
                    $result[$connId] = $meta;
                }
            }

            return $result;
        });
    }

    /**
     * 获取当前有连接的在线节点 server_id 列表（broadcast 扇出用）。
     *
     * 返回空 → ClusterPushBus::publishBroadcast 的 targetCount=0，
     * 推送阶段可对 data.offline_user_ids 落库。
     *
     * @return string[]
     */
    public static function getAllNodeIds(): array
    {
        return self::execute(function ($redis) {
            $items = $redis->sMembers(self::nodesKey());

            return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
        });
    }

    /**
     * 清理心跳超时的僵尸连接索引（通常在 worker 0 定时任务中调用）。
     *
     * 扫描 alive ZSET 中 score ≤ (now - idleTimeout) 的 conn_id，
     * 对每个 conn 执行与 unregister() 等价的索引清理，并删除 conn Hash。
     *
     * 用于节点进程被 kill -9、网络分区等无法触发 onClose 的场景。
     *
     * @param int $idleTimeout 空闲秒数阈值（通常取 conn_ttl 或 heartbeat 配置）
     *
     * @return int 本次清理的 conn 数量
     */
    public static function cleanupExpired(int $idleTimeout): int
    {
        return self::execute(function ($redis) use ($idleTimeout) {
            $deadline = time() - $idleTimeout;
            $connIds = $redis->zRangeByScore(self::aliveKey(), '0', (string) $deadline);
            if (!is_array($connIds) || empty($connIds)) {
                return 0;
            }

            $removed = 0;
            foreach ($connIds as $connId) {
                if (!is_string($connId) || $connId === '') {
                    continue;
                }

                // 与 unregister() 相同：先读 Hash 清理 user/group/node 反向索引，再删 conn + alive
                $meta = $redis->hGetAll(self::connKey($connId));
                if (is_array($meta) && !empty($meta)) {
                    $serverId = (string) ($meta['server_id'] ?? '');
                    $userId = (string) ($meta['user_id'] ?? '');
                    $groups = self::decodeGroups((string) ($meta['groups'] ?? ''));

                    if ($userId !== '') {
                        $redis->sRem(self::userKey($userId), $connId);
                    }
                    foreach ($groups as $group) {
                        $redis->sRem(self::groupKey($group), $connId);
                    }
                    if ($serverId !== '') {
                        $redis->sRem(self::nodeConnsKey($serverId), $connId);
                        if ((int) $redis->sCard(self::nodeConnsKey($serverId)) === 0) {
                            $redis->sRem(self::nodesKey(), $serverId);
                        }
                    }
                }

                $redis->del(self::connKey($connId));
                $redis->zRem(self::aliveKey(), $connId);
                $removed++;
            }

            return $removed;
        });
    }

    /**
     * 向目标节点写入一条跨节点推送指令。
     *
     * 按 cluster.push.transport 分流：
     * - streams：PushStreamPublisher::publish → XADD（消息持久化）
     * - pubsub：PUBLISH 到 ws:push:{APP}:{server_id}（消费进程不在线则丢失）
     *
     * @param string $serverId 目标 WebSocket 节点的 server_id
     * @param array  $message    PushMessage::event / broadcast 结构
     */
    public static function publish(string $serverId, array $message): bool
    {
        if (ClusterConfig::usesPushStreams()) {
            PushStreamPublisher::publish($serverId, $message);

            return true;
        }

        return (bool) self::execute(function ($redis) use ($serverId, $message) {
            return $redis->publish(ClusterConfig::pushChannelForServer($serverId), PushMessage::encode($message));
        });
    }

    /**
     * 批量向多个节点写入推送指令（pipeline 优化）。
     *
     * ClusterPushBus::fanout() 按 server_id 分组后调用，一次 Redis 往返完成多节点扇出。
     *
     * @param array<int, array{0: string, 1: array}> $items [serverId, PushMessage]
     */
    public static function publishMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        if (ClusterConfig::usesPushStreams()) {
            PushStreamPublisher::publishMany($items);

            return;
        }

        self::execute(function ($redis) use ($items) {
            $payloads = [];
            foreach ($items as $item) {
                $serverId = (string) ($item[0] ?? '');
                $message = $item[1] ?? null;
                if ($serverId === '' || !is_array($message)) {
                    continue;
                }
                $payloads[] = [
                    ClusterConfig::pushChannelForServer($serverId),
                    PushMessage::encode($message),
                ];
            }
            if ($payloads !== []) {
                $redis->publishMany($payloads);
            }
        });
    }

    /** 在 Worker 协程内复用 Redis 连接执行命令（ClusterRedisClient 按协程单例，失败自动重连） */
    private static function execute(callable $callback)
    {
        return ClusterRedisClient::execute($callback);
    }

    /** `{prefix}conn:{conn_id}` */
    private static function connKey(string $connId): string
    {
        return ClusterConfig::keyPrefix() . 'conn:' . $connId;
    }

    /** `{prefix}user:{user_id}` */
    private static function userKey(string $userId): string
    {
        return ClusterConfig::keyPrefix() . 'user:' . $userId;
    }

    /** `{prefix}group:{group}` */
    private static function groupKey(string $group): string
    {
        return ClusterConfig::keyPrefix() . 'group:' . $group;
    }

    /** `{prefix}node:{server_id}` */
    private static function nodeConnsKey(string $serverId): string
    {
        return ClusterConfig::keyPrefix() . 'node:' . $serverId;
    }

    /** `{prefix}nodes` */
    private static function nodesKey(): string
    {
        return ClusterConfig::keyPrefix() . 'nodes';
    }

    /** `{prefix}alive` */
    private static function aliveKey(): string
    {
        return ClusterConfig::keyPrefix() . 'alive';
    }

    /** 将 conn Hash 中的 groups JSON 解析为数组；unregister / cleanupExpired 据此清理 group Set */
    private static function decodeGroups(string $groups): array
    {
        $items = $groups === '' ? [] : json_decode($groups, true);

        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }
}

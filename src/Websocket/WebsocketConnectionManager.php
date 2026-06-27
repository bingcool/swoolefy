<?php
/**
 * +----------------------------------------------------------------------
 * | swoolefy framework bases on swoole extension development, we can use it easily!
 * +----------------------------------------------------------------------
 * | Licensed ( https://opensource.org/licenses/MIT )
 * +----------------------------------------------------------------------
 * | @see https://github.com/bingcool/swoolefy
 * +----------------------------------------------------------------------
 */

namespace Swoolefy\Websocket;

use Swoole\Http\Request;
use Swoole\WebSocket\Server;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;

class WebsocketConnectionManager
{
    // 连接主表：fd 是唯一键，保存连接元数据和心跳时间。
    public const TABLE_CONNECTIONS = 'table_websocket_connections';

    // 用户索引表：一个 user_id 允许绑定多个 fd，支持多端登录。
    public const TABLE_USERS = 'table_websocket_users';

    // 小组索引表：一个 group 对应多个 fd，用于小组广播。
    public const TABLE_GROUPS = 'table_websocket_groups';

    /** 最近一次 joinGroup 被拒绝的原因（供业务层返回错误信息） */
    private static ?string $lastJoinDenyReason = null;

    /**
     * 连接表定义在 server createTables() 前注入配置，所有 worker 共享。
     */
    public static function tableDefinitions(array $websocketConfig = []): array
    {
        $size = (int) ($websocketConfig['connection_table_size'] ?? 65536);
        $indexSize = (int) ($websocketConfig['index_table_size'] ?? $size * 2);

        return [
            self::TABLE_CONNECTIONS => [
                'size' => $size,
                'fields' => [
                    ['fd', 'int', 8],
                    ['worker_id', 'int', 8],
                    ['user_id', 'string', 128],
                    ['sid', 'string', 64],
                    ['groups', 'string', 2048],
                    ['remote_addr', 'string', 64],
                    ['user_agent', 'string', 256],
                    ['connected_at', 'int', 8],
                    ['last_active_at', 'int', 8],
                    ['is_socketio', 'int', 1],
                    ['is_polling', 'int', 1],
                    ['socketio_namespaces', 'string', 512],
                    // 全局连接 ID：{server_id}:{fd}，集群模式下用于 Redis 索引
                    ['conn_id', 'string', 160],
                ],
            ],
            self::TABLE_USERS => [
                'size' => $indexSize,
                'fields' => [
                    ['user_id', 'string', 128],
                    ['fd', 'int', 8],
                ],
            ],
            self::TABLE_GROUPS => [
                'size' => $indexSize,
                'fields' => [
                    ['group', 'string', 128],
                    ['fd', 'int', 8],
                    ['user_id', 'string', 128],
                ],
            ],
        ];
    }

    public static function open(Server $server, Request $request, array $options = []): void
    {
        $fd = (int) $request->fd;
        $now = time();
        $userId = (string) ($options['user_id'] ?? self::resolveUserId($request));
        $sid = (string) ($options['sid'] ?? '');
        $isSocketIo = !empty($options['is_socketio']) ? 1 : 0;
        $isPolling = !empty($options['is_polling']) ? 1 : 0;
        $remoteAddr = (string) ($request->server['remote_addr'] ?? '');
        $userAgent = (string) ($request->header['user-agent'] ?? '');

        $connId = Cluster\ClusterNodeIdentity::makeConnId($fd);

        // onOpen 只记录连接事实和轻量元数据，业务身份可在鉴权回调或后续消息中重新 bindUser。
        $connection = [
            'fd' => $fd,
            'worker_id' => (int) $server->worker_id,
            'user_id' => $userId,
            'sid' => $sid,
            'groups' => '',
            'remote_addr' => $remoteAddr,
            'user_agent' => mb_substr($userAgent, 0, 256),
            'connected_at' => $now,
            'last_active_at' => $now,
            'is_socketio' => $isSocketIo,
            'is_polling' => $isPolling,
            'socketio_namespaces' => (string) ($options['socketio_namespaces'] ?? ''),
            'conn_id' => $connId,
        ];
        self::setConnection($fd, $connection);

        try {
            // 集群模式：本地 Table 写入成功后，同步注册到 Redis 全局索引
            Cluster\ClusterConnectionCoordinator::onOpen($fd, $connection);
        } catch (\Throwable $throwable) {
            // Redis 注册失败时回滚本地索引，避免本机残留脏 fd
            self::close($fd);
            throw $throwable;
        }

        if ($userId !== '') {
            self::bindUser($fd, $userId);
            // 离线必达：握手已带 user_id 时触发补推 + on_reconnect 钩子
            self::notifyUserOnline($server, $fd, $userId);
        }
    }

    /**
     * 注册 polling transport 会话（虚拟 fd，HTTP 请求结束后 fd 不持久）。
     *
     * 虚拟 fd ≥ 0x40000000，push 走 SocketIOSessionManager 出站队列而非 server->push。
     * namespace 在后续 POST `40/ns,` 时写入 socketio_namespaces。
     */
    public static function openPolling(Request $request, array $options = []): void
    {
        $virtualFd = (int) ($options['virtual_fd'] ?? 0);
        if ($virtualFd <= 0) {
            throw new \InvalidArgumentException('polling session requires virtual_fd');
        }

        $now = time();
        $userId = (string) ($options['user_id'] ?? self::resolveUserId($request));
        $sid = (string) ($options['sid'] ?? '');
        $remoteAddr = (string) ($request->server['remote_addr'] ?? '');
        $userAgent = (string) ($request->header['user-agent'] ?? '');
        $connId = Cluster\ClusterNodeIdentity::makeConnId($virtualFd);

        $connection = [
            'fd' => $virtualFd,
            'worker_id' => 0,
            'user_id' => $userId,
            'sid' => $sid,
            'groups' => '',
            'remote_addr' => $remoteAddr,
            'user_agent' => mb_substr($userAgent, 0, 256),
            'connected_at' => $now,
            'last_active_at' => $now,
            'is_socketio' => 1,
            'is_polling' => 1,
            'socketio_namespaces' => '',
            'conn_id' => $connId,
        ];
        self::setConnection($virtualFd, $connection);

        try {
            Cluster\ClusterConnectionCoordinator::onOpen($virtualFd, $connection);
        } catch (\Throwable $throwable) {
            self::closePollingVirtual($virtualFd);
            throw $throwable;
        }

        if ($userId !== '') {
            self::bindUser($virtualFd, $userId);
        }
    }

    /** 关闭 polling 虚拟连接（不 disconnect WebSocket） */
    public static function closePollingVirtual(int $virtualFd): void
    {
        $connection = self::getConnection($virtualFd);
        if (!$connection) {
            SocketIO\SocketIOSessionManager::destroyVirtualFd($virtualFd);

            return;
        }

        $userId = (string) ($connection['user_id'] ?? '');
        $connId = (string) ($connection['conn_id'] ?? '');
        if ($userId !== '') {
            self::unbindUser($virtualFd, $userId);
        }

        foreach (self::decodeGroups((string) ($connection['groups'] ?? '')) as $group) {
            self::leaveGroup($virtualFd, $group, false);
        }

        self::tableDel(self::TABLE_CONNECTIONS, (string) $virtualFd);
        Cluster\ClusterConnectionCoordinator::onClose($connId);
        SocketIO\SocketIOSessionManager::destroyVirtualFd($virtualFd);
    }

    /** @return string[] */
    public static function decodeGroupsPublic(string $groups): array
    {
        return self::decodeGroups($groups);
    }

    /**
     * polling → websocket 升级时恢复小组索引（跳过 join 鉴权）。
     *
     * @param string[] $groups
     */
    public static function restoreGroupsWithoutAuth(int $fd, array $groups): void
    {
        $connection = self::getConnection($fd);
        if (!$connection || $groups === []) {
            return;
        }

        $connection['groups'] = self::encodeGroups($groups);
        self::setConnection($fd, $connection);

        foreach ($groups as $group) {
            self::tableSet(self::TABLE_GROUPS, self::groupKey($group, $fd), [
                'group' => $group,
                'fd' => $fd,
                'user_id' => (string) ($connection['user_id'] ?? ''),
            ]);
            Cluster\ClusterConnectionCoordinator::onJoinGroup(
                (string) ($connection['conn_id'] ?? ''),
                $group,
                (string) $connection['groups']
            );
        }
    }

    public static function close(int $fd): void
    {
        $connection = self::getConnection($fd);
        if (!$connection) {
            return;
        }

        $userId = (string) ($connection['user_id'] ?? '');
        $connId = (string) ($connection['conn_id'] ?? '');
        if ($userId !== '') {
            self::unbindUser($fd, $userId);
        }

        // 关闭连接时必须同步清理用户索引和小组索引，否则长运行服务会出现脏 fd。
        foreach (self::decodeGroups((string) ($connection['groups'] ?? '')) as $group) {
            // close 流程里 Redis 清理由 onClose 统一处理，此处只清本地 Table
            self::leaveGroup($fd, $group, false);
        }

        self::tableDel(self::TABLE_CONNECTIONS, (string) $fd);
        // 集群模式：清理 Redis 中 conn/user/group/node 索引
        Cluster\ClusterConnectionCoordinator::onClose($connId);
    }

    public static function bindUser(int $fd, string $userId): void
    {
        $connection = self::getConnection($fd);
        if (!$connection) {
            return;
        }

        $oldUserId = (string) ($connection['user_id'] ?? '');
        if ($oldUserId !== '' && $oldUserId !== $userId) {
            self::unbindUser($fd, $oldUserId);
        }

        // 用户索引使用 userId+fd 组合键，避免同一用户多连接互相覆盖。
        $connection['user_id'] = $userId;
        self::setConnection($fd, $connection);
        self::tableSet(self::TABLE_USERS, self::userKey($userId, $fd), [
            'user_id' => $userId,
            'fd' => $fd,
        ]);
        Cluster\ClusterConnectionCoordinator::onBindUser(
            (string) ($connection['conn_id'] ?? ''),
            $userId,
            $oldUserId
        );

        if ($userId !== '' && $oldUserId !== $userId) {
            // 会话中换绑 user（匿名→登录、切换账号）：对新 user 补推离线消息
            try {
                $server = \Swoolefy\Core\Swfy::getServer();
                if ($server instanceof Server) {
                    self::notifyUserOnline($server, $fd, $userId);
                }
            } catch (\Throwable $throwable) {
                // 非 WS Worker 或无 Server 时跳过补推
            }
        }
    }

    public static function joinGroup(int $fd, string $group, array $params = []): bool
    {
        $group = trim($group);
        $connection = self::getConnection($fd);
        if ($group === '' || !$connection) {
            return false;
        }

        // 加组鉴权：Config/websocket.php → group.join_authorizer
        $userId = (string) ($connection['user_id'] ?? '');
        $denyReason = Group\GroupJoinAuthorizerFactory::authorize($fd, $userId, $group, $params);
        if ($denyReason !== null) {
            self::$lastJoinDenyReason = $denyReason;
            \Swoolefy\Websocket\Metrics\WebsocketMetrics::recordJoinDenied();

            return false;
        }
        self::$lastJoinDenyReason = null;

        // 连接表内保存 groups 快照，close 时可以反向清理 TABLE_GROUPS 索引。
        $groups = self::decodeGroups((string) ($connection['groups'] ?? ''));
        if (!in_array($group, $groups, true)) {
            $groups[] = $group;
            $connection['groups'] = self::encodeGroups($groups);
            self::setConnection($fd, $connection);
        }

        self::tableSet(self::TABLE_GROUPS, self::groupKey($group, $fd), [
            'group' => $group,
            'fd' => $fd,
            'user_id' => (string) ($connection['user_id'] ?? ''),
        ]);
        Cluster\ClusterConnectionCoordinator::onJoinGroup(
            (string) ($connection['conn_id'] ?? ''),
            $group,
            (string) $connection['groups']
        );

        return true;
    }

    /** 最近一次 joinGroup 被拒绝的原因（供 ChatService 返回给客户端） */
    public static function getLastJoinDenyReason(): ?string
    {
        return self::$lastJoinDenyReason;
    }

    /**
     * @param bool $syncCluster close 流程传 false，避免重复写 Redis（由 onClose 统一清理）
     */
    public static function leaveGroup(int $fd, string $group, bool $syncCluster = true): bool
    {
        $group = trim($group);
        if ($group === '') {
            return false;
        }

        $connection = self::getConnection($fd);
        if ($connection) {
            $groups = array_values(array_filter(
                self::decodeGroups((string) ($connection['groups'] ?? '')),
                static fn (string $item): bool => $item !== $group
            ));
            $connection['groups'] = self::encodeGroups($groups);
            self::setConnection($fd, $connection);
        }

        self::tableDel(self::TABLE_GROUPS, self::groupKey($group, $fd));
        if ($syncCluster && $connection) {
            Cluster\ClusterConnectionCoordinator::onLeaveGroup(
                (string) ($connection['conn_id'] ?? ''),
                $group,
                (string) ($connection['groups'] ?? '')
            );
        }
        return true;
    }

    public static function touch(int $fd): void
    {
        $connection = self::getConnection($fd);
        if (!$connection) {
            return;
        }
        // 所有合法消息都会刷新本地 last_active_at；Redis touch 由 ClusterConnectionCoordinator 按 touch_interval 节流
        $connection['last_active_at'] = time();
        self::setConnection($fd, $connection);
        Cluster\ClusterConnectionCoordinator::onTouch((string) ($connection['conn_id'] ?? ''), $connection['last_active_at']);
    }

    public static function getConnection(int $fd): ?array
    {
        return self::tableGet(self::TABLE_CONNECTIONS, (string) $fd) ?: null;
    }

    public static function updateConnection(int $fd, array $connection): void
    {
        self::setConnection($fd, $connection);
    }

    public static function getConnIdByFd(int $fd): string
    {
        $connection = self::getConnection($fd);

        return (string) ($connection['conn_id'] ?? '');
    }

    public static function countLocalConnections(): int
    {
        if (!self::tableExists(self::TABLE_CONNECTIONS)) {
            return 0;
        }

        $count = 0;
        foreach (TableManager::getTable(self::TABLE_CONNECTIONS) as $row) {
            if ((int) ($row['fd'] ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }

    public static function getFdsByUser(string $userId): array
    {
        return self::filterFds(self::TABLE_USERS, static fn (array $row): bool => (string) $row['user_id'] === $userId);
    }

    public static function getFdsByGroup(string $group): array
    {
        return self::filterFds(self::TABLE_GROUPS, static fn (array $row): bool => (string) $row['group'] === $group);
    }

    public static function disconnectExpired(Server $server, int $idleTimeout): int
    {
        if ($idleTimeout <= 0 || !self::tableExists(self::TABLE_CONNECTIONS)) {
            return 0;
        }

        $now = time();
        $closed = 0;
        // 只在 worker 0 定时扫描，避免多个 worker 同时断开同一个 fd。
        foreach (TableManager::getTable(self::TABLE_CONNECTIONS) as $row) {
            $fd = (int) $row['fd'];
            $lastActiveAt = (int) $row['last_active_at'];
            if ($fd > 0 && $now - $lastActiveAt > $idleTimeout) {
                if (!empty($row['is_polling'])) {
                    self::closePollingVirtual($fd);
                    $closed++;
                    continue;
                }
                self::close($fd);
                if ($server->isEstablished($fd)) {
                    $server->disconnect($fd, 1001, 'heartbeat timeout');
                }
                $closed++;
            }
        }
        if ($closed === 0) {
            // 本机无超时时，顺带清理 Redis 中失联节点的僵尸索引
            Cluster\ClusterConnectionCoordinator::cleanupExpired($idleTimeout);
        }

        return $closed;
    }

    public static function pushToUser(Server $server, string $userId, string $payload, int $opcode = WEBSOCKET_OPCODE_TEXT): int
    {
        return self::pushToFds($server, self::getFdsByUser($userId), $payload, $opcode);
    }

    public static function pushToGroup(Server $server, string $group, string $payload, int $opcode = WEBSOCKET_OPCODE_TEXT): int
    {
        return self::pushToFds($server, self::getFdsByGroup($group), $payload, $opcode);
    }

    public static function broadcast(Server $server, string $payload, int $opcode = WEBSOCKET_OPCODE_TEXT): int
    {
        if (!self::tableExists(self::TABLE_CONNECTIONS)) {
            return 0;
        }

        $fds = [];
        foreach (TableManager::getTable(self::TABLE_CONNECTIONS) as $row) {
            $fds[] = (int) $row['fd'];
        }

        return self::pushToFds($server, $fds, $payload, $opcode);
    }

    /**
     * 按连接类型编码事件：
     * - Socket.IO 连接：42["event",{...}]
     * - 普通 WebSocket：统一 JSON 响应格式
     */
    public static function encodeEventPayload(int $fd, string $event, $data = [], string $namespace = '/'): string
    {
        $frames = self::encodeEventFrames($fd, $event, $data, $namespace);

        return (string) ($frames[0][0] ?? '');
    }

    /**
     * 编码出站帧；Socket.IO 连接自动解析 push namespace 与二进制附件。
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public static function encodeEventFrames(int $fd, string $event, $data = [], string $namespace = '/'): array
    {
        $payloadData = is_array($data) ? $data : ['value' => $data];
        $connection = self::getConnection($fd);
        if (!empty($connection['is_socketio'])) {
            // push 可通过 data.namespace / data._socketio.namespace 指定目标 ns
            if ($namespace === '/') {
                $namespace = SocketIO\SocketIONamespaceRegistry::resolvePushNamespace($payloadData);
            }

            return SocketIOPacket::encodeEventFrames($event, [$payloadData], $namespace);
        }

        return [[WebsocketResponse::event($event, $payloadData), WEBSOCKET_OPCODE_TEXT]];
    }

    public static function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        // 统一走推送分发器：cluster.enable 决定单机或跨节点
        return Cluster\PushDispatcherFactory::get()->pushEventToFd($server, $fd, $event, $data);
    }

    /**
     * 本节点直推（集群订阅进程 / 本机投递使用，不经过 Redis 扇出）。
     *
     * ## 引用模式钩子
     *
     * 在 `server->push()` 之前调用 `PushPayloadResolver::resolve()`：
     * - 业务 push 仅带 msg_id 时，由 Config/websocket.php → push.enricher 查库组装完整 message
     * - enricher 返回 null 时跳过该 fd，不向客户端发送帧
     *
     * 以下路径最终都会落到本方法：
     * - LocalPushDispatcher（单机 cluster.enable=false）
     * - ClusterPushDispatcher 本机 leg
     * - PushDeliveryHandler（跨节点 Pub/Sub 订阅后的本地投递）
     * - deliverBroadcastEventLocally（逐 fd 调用，每个 fd 独立 enrich）
     */
    /**
     * 本节点直推，返回细粒度投递状态（Streams ACK 决策用）。
     *
     * @return 'delivered'|'gone'|'skipped'|'failed'
     */
    public static function deliverEventToFdLocallyDetailed(Server $server, int $fd, string $event, $data = []): string
    {
        $connection = self::getConnection($fd);
        // polling 虚拟 fd：无 WebSocket，写入 sid 队列由 long-poll 取走
        if (!empty($connection['is_polling'])) {
            return self::deliverEventToPollingFd($fd, $event, $data);
        }

        if ($fd <= 0 || !$server->isEstablished($fd)) {
            return 'gone';
        }

        $data = Push\PushPayloadResolver::resolve($event, $data, $fd);
        if ($data === null) {
            return 'skipped';
        }

        return self::pushEncodedFrames($server, $fd, self::encodeEventFrames($fd, $event, $data))
            ? 'delivered'
            : 'failed';
    }

    /**
     * 二进制 event 可能多帧：逐帧 push，任一失败则整次投递失败。
     *
     * @param array<int, array{0: string, 1: int}> $frames
     */
    private static function pushEncodedFrames(Server $server, int $fd, array $frames): bool
    {
        foreach ($frames as [$payload, $opcode]) {
            if (!$server->push($fd, $payload, $opcode)) {
                return false;
            }
        }

        return true;
    }

    /**
     * polling 虚拟 fd：写入 sid 出站队列，由 long-poll GET 取走。
     *
     * @return 'delivered'|'gone'|'skipped'|'failed'
     */
    private static function deliverEventToPollingFd(int $fd, string $event, $data): string
    {
        $connection = self::getConnection($fd);
        if (!$connection) {
            return 'gone';
        }

        $data = Push\PushPayloadResolver::resolve($event, $data, $fd);
        if ($data === null) {
            return 'skipped';
        }

        $sid = (string) ($connection['sid'] ?? '');
        if ($sid === '') {
            return 'gone';
        }

        $payloadData = is_array($data) ? $data : ['value' => $data];
        $namespace = SocketIO\SocketIONamespaceRegistry::resolvePushNamespace($payloadData);
        // polling 无法发 BINARY 帧，二进制会编码为 `b<base64>` 块拼进 batch
        $chunks = SocketIOPacket::encodeEventPollingChunks($event, [$payloadData], $namespace);
        $batch = SocketIOPacket::encodeBatch($chunks);

        return SocketIO\SocketIOSessionManager::enqueueOutbound($sid, $batch) ? 'delivered' : 'failed';
    }

    public static function deliverEventToFdLocally(Server $server, int $fd, string $event, $data = []): bool
    {
        return self::deliverEventToFdLocallyDetailed($server, $fd, $event, $data) === 'delivered';
    }

    public static function pushEventToUser(Server $server, string $userId, string $event, $data = []): int
    {
        self::assertPushUserId($userId);

        if (Cluster\ClusterConfig::isEnabled()) {
            $result = Cluster\ClusterPushBus::publishToUser($userId, $event, $data, $server);
            Offline\OfflineMessageCoordinator::maybeStoreOfflineAfterPush($userId, $event, $data, $result);

            return $result->reportedHitCount();
        }

        $count = Cluster\PushDispatcherFactory::get()->pushEventToUser($server, $userId, $event, $data);
        Offline\OfflineMessageCoordinator::maybeStoreOffline($userId, $event, $data, $count);

        return $count;
    }

    public static function pushEventToGroup(Server $server, string $group, string $event, $data = []): int
    {
        return Cluster\PushDispatcherFactory::get()->pushEventToGroup($server, $group, $event, $data);
    }

    public static function broadcastEvent(Server $server, string $event, $data = []): int
    {
        return Cluster\PushDispatcherFactory::get()->broadcastEvent($server, $event, $data);
    }

    /**
     * 本节点全量广播：遍历本地 Swoole\Table，逐 fd 投递。
     *
     * 每个 fd 经 deliverEventToFdLocally → PushPayloadResolver，引用模式下按 fd 独立 enrich。
     */
    public static function deliverBroadcastEventLocally(Server $server, string $event, $data = []): int
    {
        if (!self::tableExists(self::TABLE_CONNECTIONS)) {
            return 0;
        }

        $count = 0;
        // 仅遍历本节点 Swoole\Table，不跨机
        foreach (TableManager::getTable(self::TABLE_CONNECTIONS) as $row) {
            $fd = (int) $row['fd'];
            if (self::deliverEventToFdLocally($server, $fd, $event, $data)) {
                $count++;
            }
        }

        return $count;
    }

    private static function pushToFds(Server $server, array $fds, string $payload, int $opcode): int
    {
        $count = 0;
        foreach (array_unique($fds) as $fd) {
            // 推送前再次确认连接仍处于 established，防止 table 中短暂存在已关闭 fd。
            if ($fd > 0 && $server->isEstablished($fd) && $server->push($fd, $payload, $opcode)) {
                $count++;
            }
        }

        return $count;
    }

    private static function resolveUserId(Request $request): string
    {
        // 默认从 query 的 uid/user_id 提取，生产项目可在 onOpen 或鉴权回调里覆盖绑定。
        return (string) ($request->get['uid'] ?? $request->get['user_id'] ?? '');
    }

    private static function userKey(string $userId, int $fd): string
    {
        return md5($userId) . ':' . $fd;
    }

    private static function groupKey(string $group, int $fd): string
    {
        return md5($group) . ':' . $fd;
    }

    private static function decodeGroups(string $groups): array
    {
        $items = $groups === '' ? [] : json_decode($groups, true);
        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }

    private static function encodeGroups(array $groups): string
    {
        return json_encode(array_values(array_unique($groups)), JSON_UNESCAPED_UNICODE);
    }

    private static function filterFds(string $table, callable $filter): array
    {
        if (!self::tableExists($table)) {
            return [];
        }

        $fds = [];
        foreach (TableManager::getTable($table) as $row) {
            if ($filter($row)) {
                $fds[] = (int) $row['fd'];
            }
        }

        return $fds;
    }

    private static function setConnection(int $fd, array $row): void
    {
        self::tableSet(self::TABLE_CONNECTIONS, (string) $fd, $row);
    }

    private static function unbindUser(int $fd, string $userId): void
    {
        self::tableDel(self::TABLE_USERS, self::userKey($userId, $fd));
    }

    private static function tableExists(string $table): bool
    {
        return TableManager::isExistTable($table);
    }

    private static function tableSet(string $table, string $key, array $row): void
    {
        if (self::tableExists($table)) {
            TableManager::set($table, $key, $row);
        }
    }

    private static function tableGet(string $table, string $key)
    {
        if (!self::tableExists($table)) {
            return false;
        }
        return TableManager::get($table, $key);
    }

    private static function tableDel(string $table, string $key): void
    {
        if (self::tableExists($table)) {
            TableManager::del($table, $key);
        }
    }

    private static function assertPushUserId(string $userId): void
    {
        // pushToUser 依赖 user 索引；空 user_id 在集群/单机均无意义
        if (trim($userId) === '') {
            throw new \InvalidArgumentException('pushToUser requires a non-empty user_id');
        }
    }

    /**
     * 用户上线入口：委托 OfflineMessageCoordinator（补推 + on_reconnect 钩子）。
     *
     * @see OfflineMessageCoordinator::onUserOnline()
     */
    private static function notifyUserOnline(Server $server, int $fd, string $userId): void
    {
        Offline\OfflineMessageCoordinator::onUserOnline($server, $fd, $userId);
    }
}

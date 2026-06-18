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
use Swoolefy\Core\BaseServer;
use Swoolefy\Core\Table\TableManager;
use Swoolefy\Websocket\SocketIO\SocketIOPacket;

class WebsocketConnectionManager
{
    // 连接主表：fd 是唯一键，保存连接元数据和心跳时间。
    public const TABLE_CONNECTIONS = 'table_websocket_connections';

    // 用户索引表：一个 user_id 允许绑定多个 fd，支持多端登录。
    public const TABLE_USERS = 'table_websocket_users';

    // 房间索引表：一个 room 对应多个 fd，用于房间广播。
    public const TABLE_ROOMS = 'table_websocket_rooms';

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
                    ['rooms', 'string', 2048],
                    ['remote_addr', 'string', 64],
                    ['user_agent', 'string', 256],
                    ['connected_at', 'int', 8],
                    ['last_active_at', 'int', 8],
                    ['is_socketio', 'int', 1],
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
            self::TABLE_ROOMS => [
                'size' => $indexSize,
                'fields' => [
                    ['room', 'string', 128],
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
        $remoteAddr = (string) ($request->server['remote_addr'] ?? '');
        $userAgent = (string) ($request->header['user-agent'] ?? '');

        $connId = Cluster\ClusterNodeIdentity::makeConnId($fd);

        // onOpen 只记录连接事实和轻量元数据，业务身份可在鉴权回调或后续消息中重新 bindUser。
        $connection = [
            'fd' => $fd,
            'worker_id' => (int) $server->worker_id,
            'user_id' => $userId,
            'sid' => $sid,
            'rooms' => '',
            'remote_addr' => $remoteAddr,
            'user_agent' => mb_substr($userAgent, 0, 256),
            'connected_at' => $now,
            'last_active_at' => $now,
            'is_socketio' => $isSocketIo,
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

        // 关闭连接时必须同步清理用户索引和房间索引，否则长运行服务会出现脏 fd。
        foreach (self::decodeRooms((string) ($connection['rooms'] ?? '')) as $room) {
            // close 流程里 Redis 清理由 onClose 统一处理，此处只清本地 Table
            self::leaveRoom($fd, $room, false);
        }

        self::tableDel(self::TABLE_CONNECTIONS, (string) $fd);
        // 集群模式：清理 Redis 中 conn/user/room/node 索引
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
    }

    public static function joinRoom(int $fd, string $room): bool
    {
        $room = trim($room);
        $connection = self::getConnection($fd);
        if ($room === '' || !$connection) {
            return false;
        }

        // 连接表内保存 rooms 快照，close 时可以反向清理 TABLE_ROOMS 索引。
        $rooms = self::decodeRooms((string) ($connection['rooms'] ?? ''));
        if (!in_array($room, $rooms, true)) {
            $rooms[] = $room;
            $connection['rooms'] = self::encodeRooms($rooms);
            self::setConnection($fd, $connection);
        }

        self::tableSet(self::TABLE_ROOMS, self::roomKey($room, $fd), [
            'room' => $room,
            'fd' => $fd,
            'user_id' => (string) ($connection['user_id'] ?? ''),
        ]);
        Cluster\ClusterConnectionCoordinator::onJoinRoom(
            (string) ($connection['conn_id'] ?? ''),
            $room,
            (string) $connection['rooms']
        );

        return true;
    }

    /**
     * @param bool $syncCluster close 流程传 false，避免重复写 Redis（由 onClose 统一清理）
     */
    public static function leaveRoom(int $fd, string $room, bool $syncCluster = true): bool
    {
        $room = trim($room);
        if ($room === '') {
            return false;
        }

        $connection = self::getConnection($fd);
        if ($connection) {
            $rooms = array_values(array_filter(
                self::decodeRooms((string) ($connection['rooms'] ?? '')),
                static fn (string $item): bool => $item !== $room
            ));
            $connection['rooms'] = self::encodeRooms($rooms);
            self::setConnection($fd, $connection);
        }

        self::tableDel(self::TABLE_ROOMS, self::roomKey($room, $fd));
        if ($syncCluster && $connection) {
            Cluster\ClusterConnectionCoordinator::onLeaveRoom(
                (string) ($connection['conn_id'] ?? ''),
                $room,
                (string) ($connection['rooms'] ?? '')
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
        // 所有合法消息都会刷新 last_active_at，心跳定时器据此识别僵尸连接。
        $connection['last_active_at'] = time();
        self::setConnection($fd, $connection);
        Cluster\ClusterConnectionCoordinator::onTouch((string) ($connection['conn_id'] ?? ''), $connection['last_active_at']);
    }

    public static function getConnection(int $fd): ?array
    {
        return self::tableGet(self::TABLE_CONNECTIONS, (string) $fd) ?: null;
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

    public static function getFdsByRoom(string $room): array
    {
        return self::filterFds(self::TABLE_ROOMS, static fn (array $row): bool => (string) $row['room'] === $room);
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

    public static function pushToRoom(Server $server, string $room, string $payload, int $opcode = WEBSOCKET_OPCODE_TEXT): int
    {
        return self::pushToFds($server, self::getFdsByRoom($room), $payload, $opcode);
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
    public static function encodeEventPayload(int $fd, string $event, $data = []): string
    {
        $payloadData = is_array($data) ? $data : ['value' => $data];
        $connection = self::getConnection($fd);
        if (!empty($connection['is_socketio'])) {
            return SocketIOPacket::event($event, [$payloadData]);
        }

        return WebsocketResponse::event($event, $payloadData);
    }

    public static function pushEventToFd(Server $server, int $fd, string $event, $data = []): bool
    {
        // 统一走推送分发器：cluster.enable 决定单机或跨节点
        return Cluster\PushDispatcherFactory::get()->pushEventToFd($server, $fd, $event, $data);
    }

    /**
     * 本节点直推（集群订阅进程 / 本机投递使用，不经过 Redis）。
     */
    public static function deliverEventToFdLocally(Server $server, int $fd, string $event, $data = []): bool
    {
        if ($fd <= 0 || !$server->isEstablished($fd)) {
            return false;
        }

        return $server->push($fd, self::encodeEventPayload($fd, $event, $data), WEBSOCKET_OPCODE_TEXT);
    }

    public static function pushEventToUser(Server $server, string $userId, string $event, $data = []): int
    {
        return Cluster\PushDispatcherFactory::get()->pushEventToUser($server, $userId, $event, $data);
    }

    public static function pushEventToRoom(Server $server, string $room, string $event, $data = []): int
    {
        return Cluster\PushDispatcherFactory::get()->pushEventToRoom($server, $room, $event, $data);
    }

    public static function broadcastEvent(Server $server, string $event, $data = []): int
    {
        return Cluster\PushDispatcherFactory::get()->broadcastEvent($server, $event, $data);
    }

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

    private static function roomKey(string $room, int $fd): string
    {
        return md5($room) . ':' . $fd;
    }

    private static function decodeRooms(string $rooms): array
    {
        $items = $rooms === '' ? [] : json_decode($rooms, true);
        return is_array($items) ? array_values(array_filter($items, 'is_string')) : [];
    }

    private static function encodeRooms(array $rooms): string
    {
        return json_encode(array_values(array_unique($rooms)), JSON_UNESCAPED_UNICODE);
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
}

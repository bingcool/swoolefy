<?php

namespace __APP_NAMESPACE__\Offline;

use Swoolefy\Websocket\Offline\OfflineMessageStoreInterface;

/**
 * 离线消息 MySQL 存储（脚手架示例，生产需实现 TODO）。
 *
 * ## 框架调用链
 *
 * ```
 * pushToUser 命中 0 → Coordinator::maybeStoreOffline() → store()
 * 上线补推         → fetchPending() → markDelivered()
 * 客户端拉取       → fetchPending() → ackDelivered() → markDelivered()
 * ```
 *
 * ## 配置（Config/websocket.php）
 *
 * ```php
 * 'offline' => [
 *     'enable' => true,
 *     'store' => [__APP_NAMESPACE__\Offline\MysqlOfflineMessageStore::class],
 *     'events' => ['chat.private', 'notify.message'], // 空数组=全部 pushToUser
 *     'replay_on_reconnect' => true,
 *     'on_reconnect' => [__APP_NAMESPACE__\Offline\OfflineReconnectCallback::class, 'onReconnect'],
 *     'replay_limit' => 100,
 * ],
 * ```
 *
 * 建表 SQL：`src/Stubs/ws_offline_messages.sql.stub`
 *
 * @see OfflineMessageStoreInterface
 */
class MysqlOfflineMessageStore implements OfflineMessageStoreInterface
{
    /**
     * 用户离线时写入 inbox。
     *
     * payload 建议 json_encode($data)；meta.trace_id 来自框架 push 链路。
     */
    public function store(string $userId, string $event, array $data, array $meta = []): string
    {
        // TODO: 对接 Db / PDO，例如：
        // $id = Db::connect('db')->table('ws_offline_messages')->insertGetId([
        //     'user_id' => $userId,
        //     'event' => $event,
        //     'payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
        //     'trace_id' => (string) ($meta['trace_id'] ?? ''),
        //     'status' => 0,
        //     'created_at' => (int) ($meta['created_at'] ?? time()),
        // ]);
        // return (string) $id;
        throw new \RuntimeException('MysqlOfflineMessageStore::store() not implemented');
    }

    /**
     * 按 id 升序分页；只返回 status=0（pending）。
     */
    public function fetchPending(string $userId, int $limit = 100, ?string $afterId = null): array
    {
        // TODO: SELECT id, event, payload, trace_id, created_at
        //       FROM ws_offline_messages
        //       WHERE user_id=? AND status=0 AND id > ?
        //       ORDER BY id ASC LIMIT ?
        // 返回项：['id','event','data'=>json_decode(payload),'trace_id','created_at']
        throw new \RuntimeException('MysqlOfflineMessageStore::fetchPending() not implemented');
    }

    public function markDelivered(string $userId, array $messageIds): int
    {
        // TODO: UPDATE ws_offline_messages
        //       SET status=1, delivered_at=UNIX_TIMESTAMP()
        //       WHERE user_id=? AND id IN (...) AND status=0
        throw new \RuntimeException('MysqlOfflineMessageStore::markDelivered() not implemented');
    }

    public function countPending(string $userId): int
    {
        // TODO: SELECT COUNT(*) FROM ws_offline_messages WHERE user_id=? AND status=0
        return 0;
    }
}

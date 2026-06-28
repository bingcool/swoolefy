<?php
/**
 * WebSocket 配置
 *
 * Socket.IO 事件路由见 Config/socketio.php
 */

return [
    // 连接表容量，生产环境按最大连接数预估后上调
    'connection_table_size' => 65536,
    'index_table_size'      => 131072,
    // 框架级心跳超时清理：客户端需定期发送 ping 或业务消息刷新 last_active_at
    'heartbeat_check_interval' => 30,
    'heartbeat_idle_time'      => 90,
    // 分片帧重组后的单条消息最大字节数，默认跟随 Protocol/conf.php 的 package_max_length
    'max_fragment_payload'     => 0,
    /*
     * 握手鉴权（生产环境建议 enable=true）
     *
     * require_user_id：开启鉴权后必须绑定非空 user_id，禁止匿名连接与 pushToUser('')
     * callback：校验 JWT/Session 并返回 ['user_id' => '...']
     */
    'auth' => [
        'enable' => false,
        'require_user_id' => true,
        'tokens' => [],
        'callback' => [__APP_NAMESPACE__\Auth\WebsocketAuthCallback::class, 'authenticate'],
    ],
    /*
     * 加组鉴权（join_authorizer 返回非空字符串表示拒绝）
     */
    'group' => [
        'join_authorizer' => [__APP_NAMESPACE__\Auth\WebsocketGroupJoinAuthorizer::class, 'authorize'],
    ],
    // 多机水平扩展：本地 Table 管 fd，Redis 管全局索引
    'cluster' => [
        'enable' => false,
        // 生产环境必须为每个实例配置唯一 server_id（禁止 rand）；推荐 env('WS_SERVER_ID')
        'server_id' => env('WS_SERVER_ID', ''),
        'redis' => [
            // 默认读取 Config/dc.php 的 websocket_cluster_redis.host/port，可在此覆盖
            // client: auto（优先 phpredis）| phpredis | predis
            'client' => 'auto',
            'key_prefix' => 'ws:__APP_NAMESPACE__:',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'database' => 0,
            'timeout' => 2.0,
        ],
        'push' => [
            'channel_prefix' => 'ws:push:__APP_NAMESPACE__:',
            'transport' => 'streams',
            'delivery_process_num' => 1,
            'stream_group' => 'deliver',
            'stream_max_len' => 50000,
            'stream_claim_idle_ms' => 30000,
            'stream_block_ms' => 5000,
            'stream_read_count' => 10,
            /*
             * 推送去重：Redis SET push:dedup:{msg_id} EX ttl
             * 防止 XAUTOCLAIM / 重试导致同一条 PushMessage 重复 server->push
             */
            'dedup' => [
                'enable' => true,
                'ttl' => 86400,
            ],
        ],
        'conn_ttl' => 180,
        'cleanup_interval' => 30,
        'touch_interval' => 30,
        // 投递 outcome=gone 时主动清理 Redis 索引的节流间隔（秒）
        'gone_cleanup_interval' => 5,
        'on_redis_failure' => 'reject_open',
    ],
    'push' => [
        'enricher' => [__APP_NAMESPACE__\Push\MessagePushEnricher::class, 'enrich'],
    ],
    /*
     * 内置可观测性指标（Swoole\Table 跨 Worker 累计，worker 0 定时刷新 gauge）
     *
     * snapshot() 输出 ws_connections_total / ws_push_delivered / ws_push_failed /
     * ws_join_denied_total / redis_stream_pending / redis_stream_lag_ms
     */
    'metrics' => [
        'enable' => true,
        'refresh_interval' => 10,
    ],
    /*
     * 用户离线必达（Streams 只保证消费进程不丢，不保证离线用户收到）
     *
     * pushToUser 命中 0 条连接 → 写入 offline.store
     * 上线 → replay_on_reconnect 自动补推 + on_reconnect 钩子
     * 客户端也可 WS/HTTP 拉取：OfflineMessageCoordinator::pullPending / ackDelivered
     */
    'offline' => [
        'enable' => false,
        'store' => [__APP_NAMESPACE__\Offline\MysqlOfflineMessageStore::class],
        'events' => ['chat.private', 'notify.message'], // 空数组=所有 pushToUser
        'replay_on_reconnect' => true,
        'on_reconnect' => [__APP_NAMESPACE__\Offline\OfflineReconnectCallback::class, 'onReconnect'],
        'replay_limit' => 100,
    ],
    /*
     * 优雅停机：SIGTERM / cli stop 时停止 accept → drain Stream PEL → 退出 Worker
     *
     * drain_timeout 建议 ≥ setting.max_wait_time，确保推送消费与连接处理均完成
     */
    'graceful_shutdown' => [
        'enable' => true,
        'drain_timeout' => 30,
        'reject_reason' => 'server shutting down',
    ],
];

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
        ],
        'conn_ttl' => 180,
        'cleanup_interval' => 30,
        'touch_interval' => 30,
        'on_redis_failure' => 'reject_open',
    ],
    'push' => [
        'enricher' => [__APP_NAMESPACE__\Push\MessagePushEnricher::class, 'enrich'],
    ],
];

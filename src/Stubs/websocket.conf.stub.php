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
    'auth' => [
        'enable' => false,
        // 示例：['dev-token']，也可配置 callback(Request $request, string $token): bool|array
        'tokens' => [],
        'callback' => null,
    ],
    // 多机水平扩展：本地 Table 管 fd，Redis 管全局索引，按 server_id 频道扇出推送
    'cluster' => [
        'enable' => false,
        // 生产环境必须为每个实例配置唯一 server_id，例如 ws-prod-01
        'server_id' => '',
        'redis' => [
            // 默认读取 Config/dc.php 的 redis.host/port，可在此覆盖
            'key_prefix' => 'ws:__APP_NAMESPACE__:',
            'password' => '',
            'database' => 0,
            'timeout' => 2.0,
        ],
        'push' => [
            'channel_prefix' => 'ws:push:__APP_NAMESPACE__:',
        ],
        'conn_ttl' => 180,
        'cleanup_interval' => 30,
        // Redis 故障策略：reject_open 拒绝新连接；local_only 仅本机可用
        'on_redis_failure' => 'reject_open',
    ],
];

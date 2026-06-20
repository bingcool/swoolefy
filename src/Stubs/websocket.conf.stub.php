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
            // 推送投递并行消费进程数；>1 时订阅进程入队，多进程 BRPOP 并行 server->push()
            'delivery_process_num' => 1,
            // 可选，自定义本节点投递队列 key；默认 {key_prefix}push:queue:{server_id}
            // 'delivery_queue_key' => '',
        ],
        'conn_ttl' => 180,
        'cleanup_interval' => 30,
        // Redis touch 写间隔（秒），本地 Table 仍每条消息刷新；默认跟随 heartbeat_check_interval
        'touch_interval' => 30,
        // Redis 故障策略：reject_open 拒绝新连接；local_only 仅本机可用
        'on_redis_failure' => 'reject_open',
    ],
    /*
     * 推送引用模式（与 cluster.push 频道配置无关）
     *
     * 典型流程：
     *   业务 push { msg_id: "m-1001" } → Redis 总线轻量传输
     *   → 各节点 deliverEventToFdLocally → enricher 查库
     *   → server->push({ message: { msg: "..." } })
     *
     * enricher 配置形式：
     *   - [Class, 'method']  类实例方法（推荐）
     *   - Class::class         实现 PushPayloadEnricherInterface 的类
     *   - callable             匿名函数 function ($event, $data, $fd): ?array
     */
    'push' => [
        'enricher' => [__APP_NAMESPACE__\Push\MessagePushEnricher::class, 'enrich'],
    ],
];

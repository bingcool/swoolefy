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
        // 静态方法 + JwtAuthGuard；必须返回 ['user_id'=>…]，禁止返回 true（会读 query uid）
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
        /*
         * 跨节点推送总线（HTTP/外部进程 → 本机 WS Worker 的 push 指令通道）
         *
         * streams（默认）：Redis Stream + 消费组，崩溃可 XAUTOCLAIM 恢复
         * pubsub：Redis PUBLISH，消费进程不在线则丢消息
         */
        'push' => [
            /**
             * Pub/Sub 频道前缀；完整频道 = prefix + server_id
             * 例：ws:push:App:node-1。仅 transport=pubsub 时用于 SUBSCRIBE/PUBLISH
             */
            'channel_prefix' => 'ws:push:__APP_NAMESPACE__:',

            /**
             * 推送传输：streams | pubsub
             * - streams：XADD → XREADGROUP，推荐生产
             * - pubsub：兼容旧模式，不持久化
             */
            'transport' => 'streams',

            /**
             * 本节点并行投递进程数（addProcess）
             * - streams：同组内 N 个 consumer 竞争消费，>1 可提高吞吐
             * - pubsub：=1 时订阅进程直推；>1 时订阅进程只入本地 List，另起 N 个 DeliveryProcess BRPOP
             */
            'delivery_process_num' => 1,

            /**
             * Redis Stream 消费组名（XGROUP CREATE / XREADGROUP）
             * 同一 server 的 stream 共用一组；勿与其他业务组名冲突
             */
            'stream_group' => 'deliver',

            /**
             * Stream 近似最大长度（XADD MAXLEN ~）
             * 超出后裁剪旧条目，防止无限堆积；过小可能丢掉尚未投递的指令
             */
            'stream_max_len' => 50000,

            /**
             * XAUTOCLAIM 最小空闲毫秒：pending 超过该时间才被其他 consumer 认领
             * 过小易抢尚未 ACK 的在途消息；过大则崩溃恢复变慢
             */
            'stream_claim_idle_ms' => 30000,

            /**
             * XREADGROUP BLOCK 毫秒（拉取新消息阻塞时长）
             * 优雅停机启用时框架会压到 ≤500ms，以便更快感知 shutting_down
             */
            'stream_block_ms' => 5000,

            /**
             * 单次 XREADGROUP / XAUTOCLAIM 最多拉取条数（1~100）
             * 越大单批吞吐越高，单次循环占用越久
             */
            'stream_read_count' => 10,

            /*
             * 推送去重：Redis SET push:dedup:{msg_id} EX ttl
             * 防止 XAUTOCLAIM / 重试导致同一条 PushMessage 重复 server->push
             */
            'dedup' => [
                /** 是否启用按 msg_id 去重 */
                'enable' => true,
                /** 去重键 TTL（秒）；应覆盖「投递 + 可能重试」窗口，默认 1 天 */
                'ttl' => 86400,
            ],
        ],

        /**
         * Redis 连接索引 TTL（秒）
         * 超过未 touch 的 fd 索引视为过期，由 cleanup 清理；须大于 touch_interval
         */
        'conn_ttl' => 180,

        /**
         * 过期连接索引扫描间隔（秒）
         * Worker 定时扫 Redis 中过期 conn，删除脏索引
         */
        'cleanup_interval' => 30,

        /**
         * 活跃连接续期间隔（秒）
         * 已建立连接周期性 EXPIRE/touch Redis 索引；应小于 conn_ttl，建议对齐 heartbeat
         */
        'touch_interval' => 30,

        /**
         * 投递 outcome=gone（fd 已断开）时，主动清理 Redis 索引的节流间隔（秒）
         * 同一连接短时间内多次 gone 不重复打 Redis，降低风暴
         */
        'gone_cleanup_interval' => 5,

        /**
         * 集群 Redis 操作失败时的建连策略
         * - reject_open：握手/open 阶段注册失败则断开（1011），保证索引一致
         * - allow_open：允许建连但可能无集群索引（单机可用、跨节点推送可能找不到）
         */
        'on_redis_failure' => 'reject_open',
    ],
    'push' => [
        'enricher' => [__APP_NAMESPACE__\Push\MessagePushEnricher::class, 'enrich'],
    ],
    /*
     * 内置可观测性指标（Swoole\Table 跨 Worker 累计，worker 0 定时刷新 gauge）
     *
     * snapshot() 输出 ws_connections_total / ws_push_delivered / ws_push_failed /
     * ws_join_denied_total / ws_push_dedup_skipped / redis_stream_pending / redis_stream_lag_ms
     *
     * on_snapshot：业务侧告警钩子（当前快照 + 上次快照），框架不内置阈值判断
     */
    'metrics' => [
        'enable' => true,
        'refresh_interval' => 10,
        /*
         * function (array $current, ?array $previous): void
         *
         * 业务可在此实现：
         * - redis_stream_pending 持续升高告警
         * - ws_push_failed 突增告警
         * - ws_push_dedup_skipped 异常抬升告警
         */
        'on_snapshot' => [__APP_NAMESPACE__\Metrics\WebsocketMetricsAlertHook::class, 'handle'],
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
     * 优雅停机：SIGTERM / cli stop
     *
     * 顺序：停接新连接与新业务帧 → 推送消费进程 drain（Stream PEL / PubSub List）
     * → Worker 收尾并 disconnect 剩余 fd。
     *
     * drain_timeout 建议 ≥ setting.max_wait_time；
     * StopCmd 会按 drain_timeout + max_wait_time 自动拉长强制 kill 等待。
     */
    'graceful_shutdown' => [
        'enable' => true,
        'drain_timeout' => 30,
        'reject_reason' => 'server shutting down',
    ],
];

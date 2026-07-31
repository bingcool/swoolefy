<?php
/**
 * Socket.IO 配置
 *
 * 支持 Engine.IO v4 的 websocket 与 long-polling transport。
 * 支持多 namespace、二进制附件（WebSocket 分帧 / polling base64）。
 *
 * long-polling 需 Protocol/conf.php 中 accept_http=true。
 */

return [
    'enable' => true,
    'ping_interval' => 25,
    'ping_timeout'  => 20,
    'max_payload'   => 1000000,
    // 二进制附件 pending 老化：空闲超时 / 总生命周期（秒）；持续小帧不能无限续期
    'binary_idle_timeout' => 60,
    'binary_max_lifetime' => 300,
    'binary_max_attachments' => 32,
    'binary_max_bytes' => 2097152,
    'allow_polling' => true,
    'poll_timeout' => 25,
    'transports' => ['websocket', 'polling'],
    // polling 跨 Worker 共享：auto=cluster 或多 Worker 时用 Redis 出站队列 + Table sid 索引
    'polling' => [
        'shared_store' => 'auto', // auto | memory | redis
        'session_ttl' => 180,
        'outbound_max_len' => 128,
        'short_poll_wait_sec' => 2,
        'session_touch_interval' => 15,
    ],
    // `*` 允许任意 namespace；生产可改为 ['/', '/chat', '/admin']
    'allowed_namespaces' => ['*'],
    'event_routes' => [
        'chat.send' => 'Service/Chat/Send',
        'chat.private' => 'Service/Chat/SendPrivate',
        'group.join' => 'Service/Chat/JoinGroup',
        'group.leave' => 'Service/Chat/LeaveGroup',
    ],
    // 按 namespace 覆盖 event 路由
    'namespaces' => [
        '/admin' => [
            'event_routes' => [
                'admin.broadcast' => 'Service/Admin/Broadcast',
            ],
        ],
    ],
];

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
    'allow_polling' => true,
    'poll_timeout' => 25,
    'transports' => ['websocket', 'polling'],
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

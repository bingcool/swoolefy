<?php
/**
 * Socket.IO 配置
 *
 * 支持 Engine.IO v4 的 websocket 与 long-polling transport。
 * event_routes 将客户端 emit 的事件名映射到 Router/service.php 中的 endpoint。
 *
 * long-polling 需 Protocol/conf.php 中 accept_http=true。
 */

return [
    'enable' => true,
    'ping_interval' => 25,
    'ping_timeout'  => 20,
    'max_payload'   => 1000000,
    // 启用 HTTP long-polling（默认 socket.io-client 会先 polling 再 upgrade websocket）
    'allow_polling' => true,
    'poll_timeout' => 25,
    'transports' => ['websocket', 'polling'],
    // Socket.IO event 到 service.php endpoint 的映射；未配置时 chat.send => chat/send
    'event_routes' => [
        'chat.send' => 'Service/Chat/Send',
        'chat.private' => 'Service/Chat/SendPrivate',
        'group.join' => 'Service/Chat/JoinGroup',
        'group.leave' => 'Service/Chat/LeaveGroup',
    ],
];

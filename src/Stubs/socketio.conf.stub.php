<?php
/**
 * Socket.IO 配置
 *
 * 当前实现支持 Socket.IO v4 的 websocket transport，不支持 long-polling。
 * event_routes 将客户端 emit 的事件名映射到 Router/service.php 中的 endpoint。
 */

return [
    'enable' => true,
    'ping_interval' => 25,
    'ping_timeout'  => 20,
    'max_payload'   => 1000000,
    // Socket.IO event 到 service.php endpoint 的映射；未配置时 chat.send => chat/send
    'event_routes' => [
        'chat.send' => 'Service/Chat/Send',
        'chat.private' => 'Service/Chat/SendPrivate',
        'group.join' => 'Service/Chat/JoinGroup',
        'group.leave' => 'Service/Chat/LeaveGroup',
    ],
];

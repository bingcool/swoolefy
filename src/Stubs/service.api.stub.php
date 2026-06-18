<?php

return [
    'Service/Demo/ReportMsg' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\DemoService::class, 'reportMsg'],
    ],

    'Service/Demo/Ping' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\DemoService::class, 'ping'],
    ],

    'Service/Chat/Send' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\ChatService::class, 'sendMessage'],
    ],

    'Service/Chat/JoinRoom' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\ChatService::class, 'joinRoom'],
    ],

    'Service/Chat/LeaveRoom' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\ChatService::class, 'leaveRoom'],
    ],
];

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

    'Service/Chat/JoinGroup' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\ChatService::class, 'joinGroup'],
    ],

    'Service/Chat/LeaveGroup' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\ChatService::class, 'leaveGroup'],
    ],
];

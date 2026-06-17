<?php

return [
    'Service/Demo/ReportMsg' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\DemoService::class, 'reportMsg'],
    ],

    'Service/Demo/Ping' => [
        'dispatch_route' => [__APP_NAMESPACE__\Service\DemoService::class, 'ping'],
    ],
];

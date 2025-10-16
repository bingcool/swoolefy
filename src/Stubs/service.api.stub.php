<?php

return [
    // demo服务endPoint末端
    'Service/Demo/ReportMsg' => [
        // 前置handle
        'beforeHandle1' => function($params) {

        },

        // 服务调度handle
        'dispatch_route' => [\UdpService\Service\DemoService::class, 'reportMsg'],

        // 后置handle
        'afterHandle1' => function($params) {

        },
    ],

];
<?php

return [

    'Service/Location/ReportLocationService::reportUserLocation' => [

        // 前置handle
        'beforeHandle1' => function($params) {

        },

        // 服务调度handle
        'dispatch_route' => 'UdpService/Service/Location/ReportLocationService::reportUserLocation',

         // 后置handle
        'afterHandle1' => function($params) {

        },
    ],

];
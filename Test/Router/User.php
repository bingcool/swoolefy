<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Core\Application;

/**
 * Module/Controller 下的控制器路由
 */

return [
    '/user/user-order/userList' => [
        'beforeHandle' => function(Request $request) {
            $name = Application::getApp()->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
    ],

    '/user/user-order/save-order' => [
        'beforeHandle' => function(Request $request) {
            $name = Application::getApp()->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]
];
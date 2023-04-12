<?php

namespace Test\Router;

use Swoole\Http\Request;

/**
 * Module/Controller 下的控制器路由
 */

return [
    '/user/user-order/userList' => [
        'beforeHandle' => function(Request $request) {
        },
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
    ]
];
<?php

namespace Test\Router\Product;

use Swoole\Http\Request;
use Swoolefy\Core\Application;

/**
 * Controller 下的控制器路由
 */

return [

    '/list/mylist' => [
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(Request $request) {
            var_dump('afterHandle');
        },
        'afterHandle1' => function(Request $request) {
            var_dump('afterHandle1');
        },
    ]
];
<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Core\Application;

/**
 * Module/Controller 下的控制器路由
 */

return [
    '/demo/demo/test' => [
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },

        'dispatch_route' => [\Test\Module\Demo\Controller\DemoController::class, 'test'],

        'afterHandle' => function(Request $request) {
            var_dump('afterHandle');
        },

        'afterHandle1' => function(Request $request) {
            var_dump('afterHandle1');
        },
    ]
];
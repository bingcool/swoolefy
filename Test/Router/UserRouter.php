<?php

namespace Test\Router;

use Swoole\Http\Request;

return [

    '/index/mybingcool' => [
        'beforeHandle' => function(Request $request) {
            var_dump($request);
        },
        'handle' => [\Test\Controller\IndexController::class, 'testLog'],
        'afterHandle' => function(Request $request) {

        },
        'afterHandle1' => function(Request $request) {

        },
    ],

    '/user/userList' => [
        'handle' => [\Test\Controller\IndexController::class, 'testLog'],
    ]
];
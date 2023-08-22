<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Http\Route;

/**
 * Module/Controller 下的控制器路由
 */

//return [
//    '/demo/demo/test' => [
//        'beforeHandle' => function(Request $request) {
//            var_dump('beforeHandle');
//        },
//
//        'dispatch_route' => [\Test\Module\Demo\Controller\DemoController::class, 'test'],
//
//        'afterHandle' => function(Request $request) {
//            var_dump('afterHandle');
//        },
//
//        'afterHandle1' => function(Request $request) {
//            var_dump('afterHandle1');
//        },
//    ]
//];
Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件
    'middleware' => [
        'validate',
        'loginHandle',
    ],
], function ($groupMeta) {
    Route::get('/demo/test', [
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
    ]);
});

Route::group([
    // 路由前缀
    'prefix' => 'api1',
    // 路由中间件
    'middleware' => [
        'validate',
        'loginHandle',
    ],
], function ($groupMeta) {
    Route::get('/demo/test', [
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
    ]);
});




<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * Module/Controller 下的控制器路由
 */

Route::group([
    // 路由前缀
    'prefix' => 'demo',
    // 路由中间件
    'middleware' => [
        ValidLoginMiddleware::class
    ],
], function () {
    // /demo/test
    Route::get('/test', [
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Module\Demo\Controller\DemoController::class, 'test'],

        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle1');
        },
    ]);

    // /demo/test1
    Route::get('/test1', [
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Module\Demo\Controller\DemoController::class, 'test1'],

        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle1');
        },
    ]);
});






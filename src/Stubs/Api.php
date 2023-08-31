<?php

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件,多个按顺序执行
    'middleware' => [
        \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]
], function () {

    Route::get('/', [
        // 前置路由,闭包函数形式
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);


    Route::get('/index/index', [
        // 前置路由,闭包函数形式
        'beforeHandle1' => function(RequestInput $requestInput) {
            $name = $requestInput->getPostParams('name');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由1, 闭包函数形式
        'afterHandle1' => function(RequestInput $requestInput) {

        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

    ]);
});
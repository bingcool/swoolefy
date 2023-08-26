<?php

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Http\Route;

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
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle1' => function(Request $request) {
            var_dump('afterHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);


    Route::get('/index/index', [
        // 前置路由,闭包函数形式
        'beforeHandle1' => function(Request $request) {
            $name = Application::getApp()->getPostParams('name');
        },

        // 前置路由,中间件类形式(推荐)
        'beforeHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由1, 闭包函数形式
        'afterHandle1' => function(Request $request) {

        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

    ]);
});
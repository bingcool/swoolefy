<?php

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;
use Swoolefy\Core\Coroutine\Context;

// 直接路由-不分组
Route::get('/index/index', [
    'beforeHandle' => function(RequestInput $requestInput) {
        Context::set('name', 'bingcool');
        $name = $requestInput->getPostParams('name');
    },

    // 这里需要替换长对应的控制器命名空间
    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

    'afterHandle' => function(RequestInput $requestInput) {

    },
    'afterHandle1' => function(RequestInput $requestInput) {

    },
]);

// 分组路由
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

        // 前置路由,中间件数组类形式(推荐)
        'beforeHandle3' => [
            \Test\Middleware\Route\ValidLoginMiddleware::class,
            \Test\Middleware\Route\ValidLoginMiddleware::class,
        ],

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },

        // 前置路由,中间件类形式(推荐)
        'afterHandle2' => \Test\Middleware\Route\ValidLoginMiddleware::class,

        // 前置路由,中间件数组类形式(推荐)
        'afterHandle3' => [
            \Test\Middleware\Route\ValidLoginMiddleware::class,
            \Test\Middleware\Route\ValidLoginMiddleware::class
        ],
    ]);
});
<?php

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

/**
 * @api api公共路由
 */

// 分组路由
Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件,多个按顺序执行
    'middleware' => [
        \App\Middleware\Route\ValidLoginMiddleware::class,
    ]
], function () {

    Route::get('/index/index', [
        // 前置路由处理
        'before_handle' => function (RequestInput $requestInput) {
            $requestInput->setValue('name', 'swoolefy');
        },
        // 前置路由,中间件数组类形式(推荐)
        'before_middleware' => [
            \App\Middleware\Route\ValidLoginMiddleware::class,
            \App\Middleware\Route\ValidLoginMiddleware::class,
        ],

        // 控制器action
        'dispatch_route' => [\App\Controller\IndexController::class, 'index'],

        // 后置路由中间件数组类形式(推荐)
        'after_middleware' => [
            \App\Middleware\Route\ValidLoginMiddleware::class,
            \App\Middleware\Route\ValidLoginMiddleware::class
        ],
    ]);
});
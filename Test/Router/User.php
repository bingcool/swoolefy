<?php

namespace Test\Router;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\RateLimiterMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * Module/Controller 下的控制器路由
 */

Route::group([
    // 路由前缀
    'prefix' => 'user',
    // 路由中间件
    'middleware' => [
        GroupTestMiddleware::class,
        ValidLoginMiddleware::class
    ]
], function () {

    Route::get('/testAddUser', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testAddUser'],
    ]);


    Route::any('/user-order/userList', [
        'beforeHandle1' => function(RequestInput $requestInput) {
            $requestInput->input('name');
            $requestInput->input('order_ids');
            $requestInput->getMethod();
        },
        'beforeHandle2' => [
            ValidLoginMiddleware::class
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
        //GroupTestMiddleware::class => GroupTestMiddleware::class
    ])
    ->enableDbDebug(true)
    ->withRateLimiterMiddleware(RateLimiterMiddleware::class,  60,60,GroupTestMiddleware::class);

    Route::post('/user-order/userList', [
        // 针对该接口启动sql-debug
        'beforeHandle' => function(RequestInput $requestInput) {
            Context::set('db_debug', true);
        },
        'beforeHandle1' => function(RequestInput $requestInput) {
            $requestInput->input('name');
            $requestInput->input('order_ids');
            $requestInput->getMethod();
        },
        'beforeHandle2' => [
            GroupTestMiddleware::class
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
        //GroupTestMiddleware::class => GroupTestMiddleware::class
    ]);

    Route::any('/user-order/userList1', [
        'beforeHandle2' => [
            ValidLoginMiddleware::class
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList1'],
        //GroupTestMiddleware::class => GroupTestMiddleware::class
    ]);

    Route::any('/user-order/logOrder', [
        'beforeHandle2' => [
            ValidLoginMiddleware::class
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\LogOrderController::class, 'testLog'],
        //GroupTestMiddleware::class => GroupTestMiddleware::class
    ]);

    Route::get('/user-order/save-order', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);

    Route::get('/user-order/update-order', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'updateOrder'],
    ]);


    Route::get('/testTransactionAddOrder', [
        'before-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testTransactionAddOrder'],
        'after-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);

    Route::get('/order/list', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'list'],
    ]);

    Route::get('/order/add', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);

    Route::get('/user-order/save-pg-order', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\PgController::class, 'savePgOrder'],
    ]);

    Route::get('/user-order/save-pg-order1', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\PgController::class, 'savePgOrder1'],
    ]);

});
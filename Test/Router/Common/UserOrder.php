<?php

namespace Test\Router;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\Middleware\CorsMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\RateLimiterMiddleware;
use Test\Middleware\Route\SendMailMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * UserOrderController — Db 综合稳定性测试
 * @see \Test\Module\Order\Controller\UserOrderController
 */

Route::group([
    'prefix' => 'user',
    'middleware' => [
        CorsMiddleware::class,
        GroupTestMiddleware::class,
        ValidLoginMiddleware::class,
    ],
], function () {
    // ANY /user/user-order/userList — 全量 Db cases
    Route::any('/user-order/userList', [
        'beforeHandle1' => function (RequestInput $requestInput) {
            $requestInput->input('name');
            $requestInput->input('order_ids');
            $requestInput->getMethod();
        },
        'beforeHandle2' => [
            CorsMiddleware::class,
            ValidLoginMiddleware::class,
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
        'afterMiddleware' => [
            SendMailMiddleware::class,
        ],
    ])
        ->enableDbDebug()
        ->enableCacheRouteMeta()
        ->withRateLimiterMiddleware(RateLimiterMiddleware::class, 60, 60, GroupTestMiddleware::class);

    // ANY /user/user-order/userList1 — 协程专项
    Route::any('/user-order/userList1', [
        'beforeHandle' => function (RequestInput $requestInput) {
            Context::set('db_debug', false);
        },
        'beforeHandle1' => function (RequestInput $requestInput) {
            $requestInput->input('name');
            $requestInput->input('order_ids');
            $requestInput->getMethod();
        },
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList1'],
        'afterMiddleware' => [
            SendMailMiddleware::class,
        ],
    ])->enableDbDebug(false);

    // ANY /user/user-order/userList2 — 事务专项
    Route::any('/user-order/userList2', [
        'beforeHandle2' => [
            ValidLoginMiddleware::class,
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList2'],
    ]);

    // ANY /user/user-order/userList3 — goApp 多层嵌套 Db 查询+插入
    Route::any('/user-order/userList3', [
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList3'],
    ])->enableDbDebug();

    // ANY /user/user-order/userList4 — newQuery 复杂 SQL
    Route::any('/user-order/userList4', [
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList4'],
    ])->enableDbDebug();
});

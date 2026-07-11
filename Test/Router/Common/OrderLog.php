<?php

namespace Test\Router;

use Swoolefy\Http\Middleware\CorsMiddleware;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * LogOrderController 路由
 * @see \Test\Module\Order\Controller\LogOrderController
 */

Route::group([
    'prefix' => 'user',
    'middleware' => [
        CorsMiddleware::class,
        GroupTestMiddleware::class,
        ValidLoginMiddleware::class,
    ],
], function () {
    Route::get('/user-order/test-request', [
        'dispatch_route' => [\Test\Module\Order\Controller\LogOrderController::class, 'testRequest'],
    ])->enableCacheRouteMeta();

    Route::put('/user-order/test-request1', [
        'dispatch_route' => [\Test\Module\Order\Controller\LogOrderController::class, 'testRequest1'],
    ])->enableCacheRouteMeta();

    Route::get('/user-order/testPageRequest', [
        'dispatch_route' => [\Test\Module\Order\Controller\LogOrderController::class, 'testPageRequest'],
    ])->enableCacheRouteMeta();

    Route::any('/user-order/logOrder', [
        'beforeHandle2' => [
            CorsMiddleware::class,
            ValidLoginMiddleware::class,
        ],
        'dispatch_route' => [\Test\Module\Order\Controller\LogOrderController::class, 'testLog'],
    ])->enableCacheRouteMeta();
});

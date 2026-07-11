<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * ObjectController 路由
 * @see \Test\Controller\ObjectController
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [GroupTestMiddleware::class],
], function () {
    // GET /api/bank/addBank
    Route::get('/bank/addBank', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'addBank'],
    ]);
});

Route::group([
    'prefix' => 'user',
    'middleware' => [GroupTestMiddleware::class, ValidLoginMiddleware::class],
], function () {
    // GET /user/user-order/save-order
    Route::get('/user-order/save-order', [
        'beforeHandle' => function (RequestInput $requestInput) {
            $requestInput->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);

    // GET /user/user-order/update-order
    Route::get('/user-order/update-order', [
        'beforeHandle' => function (RequestInput $requestInput) {
            $requestInput->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'updateOrder'],
    ]);

    // GET /user/order/list
    Route::get('/order/list', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'list'],
    ]);

    // GET /user/order/add
    Route::get('/order/add', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);
});

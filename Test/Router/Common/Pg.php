<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * PgController 路由
 * @see \Test\Controller\PgController
 */

Route::group([
    'prefix' => 'user',
    'middleware' => [GroupTestMiddleware::class, ValidLoginMiddleware::class],
], function () {
    // GET /user/user-order/save-pg-order
    Route::get('/user-order/save-pg-order', [
        'beforeHandle' => function (RequestInput $requestInput) {
            $requestInput->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Controller\PgController::class, 'savePgOrder'],
    ]);

    // GET /user/user-order/save-pg-order1
    Route::get('/user-order/save-pg-order1', [
        'beforeHandle' => function (RequestInput $requestInput) {
            $requestInput->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Controller\PgController::class, 'savePgOrder1'],
    ]);

    // DELETE /user/remove-use?uid=
    Route::delete('/remove-use', [
        'dispatch_route' => [\Test\Controller\PgController::class, 'removeUser'],
    ]);

    // GET /user/pg/user-list
    Route::get('/pg/user-list', [
        'dispatch_route' => [\Test\Controller\PgController::class, 'userList'],
    ]);
});

// GET /test-curl
Route::get('/test-curl', [
    'dispatch_route' => [\Test\Controller\PgController::class, 'testCurl'],
]);

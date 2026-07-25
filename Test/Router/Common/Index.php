<?php

namespace Test\Router;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * IndexController 路由（每个 action 同一 HTTP 方法只挂一条路径）。
 *
 * @see \Test\Controller\IndexController
 */

// GET /index/index —— 首页唯一入口（勿再挂 /api/、/product/* 到同一 action）
Route::get('/index/index', [
    'beforeHandle' => function (RequestInput $requestInput) {
        Context::set('name', 'bingcool');
        $requestInput->input('name');
    },
    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
    'afterHandle' => function (RequestInput $requestInput) {
    },
])->enableCacheRouteMeta(false);

Route::group([
    'prefix' => 'api',
    'middleware' => [GroupTestMiddleware::class],
], function () {
    // GET /api/index/testLog
    Route::get('/index/testLog', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testLog'],
    ]);

    // GET /api/index/testLog1
    Route::get('/index/testLog1', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testLog1'],
    ]);

    // GET /api/index/testUserList
    Route::get('/index/testUserList', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testUserList'],
    ]);
});

Route::group([
    'prefix' => 'user',
    'middleware' => [GroupTestMiddleware::class, ValidLoginMiddleware::class],
], function () {
    // GET /user/testAddUser
    Route::get('/testAddUser', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testAddUser'],
    ]);

    // GET /user/testTransactionAddOrder
    Route::get('/testTransactionAddOrder', [
        'before-validate' => ValidLoginMiddleware::class,
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testTransactionAddOrder'],
        'after-validate' => ValidLoginMiddleware::class,
    ]);
});

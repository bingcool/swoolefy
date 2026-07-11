<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * 本文件由 Api.php 拆分而来，按控制器模块维护。
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/lock-test1
    Route::get('/lock-test1', [
        'dispatch_route' => [\Test\Controller\LockController::class, 'locktest1'],
    ]);

    // GET /api/lock-test2
    Route::get('/lock-test2', [
        'dispatch_route' => [\Test\Controller\LockController::class, 'locktest2'],
    ]);

});

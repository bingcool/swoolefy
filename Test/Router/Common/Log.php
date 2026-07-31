<?php

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * 本文件由 Api.php 拆分而来，按控制器模块维护。
 *
 * @see \Test\Controller\LogController
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/log/info
    Route::get('/log/info', [
        'dispatch_route' => [\Test\Controller\LogController::class, 'info'],
    ]);

    // GET /api/log/error
    Route::get('/log/error', [
        'dispatch_route' => [\Test\Controller\LogController::class, 'error'],
    ]);

    // GET /api/log/system-error
    Route::get('/log/system-error', [
        'dispatch_route' => [\Test\Controller\LogController::class, 'systemError'],
    ]);

    // GET /api/log/goapp
    Route::get('/log/goapp', [
        'dispatch_route' => [\Test\Controller\LogController::class, 'goAppLog'],
    ]);

});

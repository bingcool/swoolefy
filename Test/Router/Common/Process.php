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

    // GET /api/send-task-worker
    Route::get('/send-task-worker', [
        'dispatch_route' => [\Test\Controller\ProcessController::class, 'sendTaskWorker'],
    ]);

    // GET /api/send-user-worker?name=xxx
    Route::get('/send-user-worker', [
        'dispatch_route' => [\Test\Controller\ProcessController::class, 'sendUserWorker'],
    ]);

});

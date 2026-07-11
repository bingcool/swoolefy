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

    // GET /api/sse/stream
    Route::get('/sse/stream', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'stream'],
    ]);

    // GET /api/sse/tick
    Route::get('/sse/tick', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'tick'],
    ]);

});

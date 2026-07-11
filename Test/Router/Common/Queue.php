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

    // GET|POST /api/queue/push
    Route::match(['GET', 'POST'], '/queue/push', [
        'dispatch_route' => [\Test\Controller\QueueController::class, 'push'],
    ]);

});

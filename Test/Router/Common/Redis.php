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

    // GET /api/redis/test
    Route::get('/redis/test', [
        'dispatch_route' => [\Test\Controller\RedisController::class, 'testRedis'],
    ]);

    // GET /api/redis/predis
    Route::get('/redis/predis', [
        'dispatch_route' => [\Test\Controller\RedisController::class, 'testPredis'],
    ]);

});

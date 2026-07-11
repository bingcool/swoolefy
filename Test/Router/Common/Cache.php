<?php

namespace Test\Router;

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

    // GET /api/cache/test
    Route::get('/cache/test', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test'],
    ]);

    // GET|POST /api/cache/test1
    Route::match(['GET', 'POST'], '/cache/test1', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
    ]);

});

// 分组外兼容：GET /cache/test1
Route::match(['GET'], '/cache/test1', [
    'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
]);

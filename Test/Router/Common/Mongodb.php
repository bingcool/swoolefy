<?php

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Mongodb 模块联调路由。
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/mongodb/verify Mongodb 模块端到端验证
    Route::get('/mongodb/verify', [
        'dispatch_route' => [\Test\Controller\MongodbController::class, 'mongodbverify'],
    ]);

});

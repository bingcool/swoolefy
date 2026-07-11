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

    // POST /api/upload/single
    Route::post('/upload/single', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'single'],
    ]);

    // POST /api/upload/multiple
    Route::post('/upload/multiple', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'multiple'],
    ]);

});

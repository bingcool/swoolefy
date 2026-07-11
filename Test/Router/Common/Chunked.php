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

    // GET /api/chunked/ndjson
    Route::get('/chunked/ndjson', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'ndjson'],
    ]);

    // GET /api/chunked/text
    Route::get('/chunked/text', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'text'],
    ]);

});

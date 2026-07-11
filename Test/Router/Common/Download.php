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

    // GET /api/download/file
    Route::get('/download/file', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'file'],
    ]);

    // GET /api/download/inline
    Route::get('/download/inline', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'inline'],
    ]);

});

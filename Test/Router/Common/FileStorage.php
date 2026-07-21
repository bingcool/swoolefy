<?php

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * FileStorageController 路由（本地盘 curl 黄金路径）。
 *
 * @see \Test\Controller\FileStorageController
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // POST /api/file-storage/put
    Route::post('/file-storage/put', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'put'],
    ]);

    // GET /api/file-storage/get
    Route::get('/file-storage/get', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'get'],
    ]);

    // GET /api/file-storage/metadata
    Route::get('/file-storage/metadata', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'metadata'],
    ]);

    // GET /api/file-storage/exists
    Route::get('/file-storage/exists', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'exists'],
    ]);

    // POST /api/file-storage/delete
    Route::post('/file-storage/delete', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'delete'],
    ]);

    // POST /api/file-storage/roundtrip
    Route::post('/file-storage/roundtrip', [
        'dispatch_route' => [\Test\Controller\FileStorageController::class, 'roundtrip'],
    ]);

});

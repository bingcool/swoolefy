<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Rag 模块路由 @see Test\\Module\\Rag
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::get('/v1/rag/config', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'config'],
    ]);
    Route::get('/v1/rag/stores', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'stores'],
    ]);
    Route::post('/v1/rag/seed', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'seed'],
    ]);
    Route::post('/v1/rag/ingest', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'ingest'],
    ]);
    Route::post('/v1/rag/retrieve', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'retrieve'],
    ]);
    Route::post('/v1/rag/ask', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'ask'],
    ]);
    Route::post('/v1/rag/workflow/qa', [
        'dispatch_route' => [\Test\Module\Rag\Controller\RagController::class, 'workflowQa'],
    ]);

});

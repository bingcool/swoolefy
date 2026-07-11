<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Research 模块路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::post('/v1/research/workflow/multi-agent', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'multiAgent'],
    ]);
    Route::post('/v1/research/workflow/mcp', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'mcp'],
    ]);
    Route::get('/v1/research/workflow/status', [
        'dispatch_route' => [\Test\Module\Research\Controller\ResearchWorkflowDemoController::class, 'status'],
    ]);

});

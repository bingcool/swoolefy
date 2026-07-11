<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * 通用 Workflow API 路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::get('/v1/workflow/list', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'list'],
    ]);
    Route::get('/v1/workflow/describe', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'describe'],
    ]);
    Route::post('/v1/workflow/run', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'run'],
    ]);
    Route::get('/v1/workflow/run/status', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'status'],
    ]);
    Route::post('/v1/workflow/run/resume', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'resume'],
    ]);
    Route::post('/v1/workflow/run/cancel', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'cancel'],
    ]);
    Route::get('/v1/workflow/pause/tasks', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'pauseTasks'],
    ]);
    Route::get('/v1/workflow/run/events', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'events'],
    ]);

});

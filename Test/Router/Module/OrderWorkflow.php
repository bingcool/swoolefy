<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Order Workflow 演示路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::post('/v1/order/workflow/process', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'process'],
    ]);
    Route::post('/v1/order/workflow/saga', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'saga'],
    ]);
    Route::get('/v1/order/workflow/status', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'status'],
    ]);
    Route::post('/v1/order/workflow/resume', [
        'dispatch_route' => [\Test\Module\Order\Controller\OrderWorkflowDemoController::class, 'resume'],
    ]);

});

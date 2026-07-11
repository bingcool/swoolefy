<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Outdoor 模块路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::post('/v1/outdoor/workflow/cycling', [
        'dispatch_route' => [\Test\Module\Outdoor\Controller\OutdoorWorkflowDemoController::class, 'cycling'],
    ]);
    Route::get('/v1/outdoor/workflow/status', [
        'dispatch_route' => [\Test\Module\Outdoor\Controller\OutdoorWorkflowDemoController::class, 'status'],
    ]);

});

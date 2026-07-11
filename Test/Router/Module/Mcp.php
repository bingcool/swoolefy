<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * MCP 模块路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::get('/v1/mcp/servers', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'servers'],
    ]);
    Route::get('/v1/mcp/servers/tools', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'tools'],
    ]);

});

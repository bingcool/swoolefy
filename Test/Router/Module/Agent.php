<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Agent 模块路由
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    Route::post('/v1/agent/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chat'],
    ]);
    Route::post('/v1/agent/chat1', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chat1'],
    ]);
    Route::post('/v1/agent/chat-thinking', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chatThinking'],
    ]);
    Route::post('/v1/agent/chat-persist', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentChatController::class, 'chatPersist'],
    ]);
    Route::post('/v1/agent/weather', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentStructuredController::class, 'weather'],
    ]);
    Route::post('/v1/agent/polish/recommendation', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentPolishController::class, 'recommendation'],
    ]);
    Route::post('/v1/agent/vision/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentVisionController::class, 'chat'],
    ]);
    Route::post('/v1/agent/stream/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentStreamController::class, 'chat'],
    ]);
    Route::post('/v1/agent/tool/weather', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentToolController::class, 'weather'],
    ]);
    Route::post('/v1/agent/tool/weather/stream', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentToolController::class, 'weatherStream'],
    ]);
    Route::post('/v1/agent/capability/resolve', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentCapabilityController::class, 'resolve'],
    ]);
    Route::post('/v1/agent/capability/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentCapabilityController::class, 'chat'],
    ]);
    Route::post('/v1/agent/middleware/chat', [
        'dispatch_route' => [\Test\Module\Agent\Controller\AgentMiddlewareController::class, 'chat'],
    ]);

});

<?php

namespace Test\Router;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * Controller 下的控制器路由
 */
Route::get('/index/index', [
    'beforeHandle' => function(RequestInput $requestInput) {
        Context::set('name', 'bingcool');
        $name = $requestInput->input('name');
    },

    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

    'afterHandle' => function(RequestInput $requestInput) {

    },
    'afterHandle1' => function(RequestInput $requestInput) {

    },
])->enableCacheRouteMeta(false);

// 分组路由
Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件
    'middleware' => [
        GroupTestMiddleware::class
    ]
], function () {
    Route::get('/', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $requestInput->setValue('name','bingcool');
        },

        'beforeHandle1' => function(RequestInput $requestInput) {
            $name = $requestInput->getValue('name');
            var_dump($name);
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
            var_dump("after:".$requestInput->getValue('name'));
        },

        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle1');
        },
    ]);

    Route::get('/index/testLog', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testLog'],
    ]);


    Route::get('/token/jwt', [
        'dispatch_route' => [\Test\Controller\TokenController::class, 'jwt'],
    ]);


    Route::get('/getUuid', [
        'dispatch_route' => [\Test\Controller\UuidController::class, 'getUuid'],
    ]);


    Route::get('/lock-test1', [
        'dispatch_route' => [\Test\Controller\LockController::class, 'locktest1'],
    ]);


    Route::get('/rate-test1', [
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'ratetest1'],
    ]);

    Route::get('/validate-test1', [
        'dispatch_route' => [\Test\Controller\ValidateController::class, 'test1'],
    ]);


    Route::get('/ws', [
        'dispatch_route' => [\Test\Controller\WsController::class, 'test1'],
    ]);

    Route::get('/send-task-worker', [
        'dispatch_route' => [\Test\Controller\ProcessController::class, 'sendTaskWorker'],
    ]);

    Route::get('/cache/test', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test'],
    ]);

    Route::match(['GET','POST'],'/cache/test1', [
        'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
    ]);

    Route::match(['GET','POST'],'/queue/push', [
        'dispatch_route' => [\Test\Controller\QueueController::class, 'push'],
    ]);

    Route::match(['GET','POST'],'/captcha/image', [
        'dispatch_route' => [\Test\Controller\CaptchaController::class, 'test'],
    ]);

    Route::match(['GET'],'/bank/addBank', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'addBank'],
    ]);

    Route::match(['GET'],'/redis/test', [
        'dispatch_route' => [\Test\Controller\RedisController::class, 'testRedis'],
    ]);

    Route::match(['GET'],'/transaction/test', [
        'dispatch_route' => [\Test\Controller\TransactionController::class, 'test'],
    ]);

    Route::get('/sse/stream', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'stream'],
    ]);

    Route::get('/sse/tick', [
        'dispatch_route' => [\Test\Controller\EventStreamController::class, 'tick'],
    ]);

    Route::get('/chunked/ndjson', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'ndjson'],
    ]);

    Route::get('/chunked/text', [
        'dispatch_route' => [\Test\Controller\ChunkedController::class, 'text'],
    ]);

    Route::get('/download/file', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'file'],
    ]);

    Route::get('/download/inline', [
        'dispatch_route' => [\Test\Controller\DownloadController::class, 'inline'],
    ]);

    Route::post('/upload/single', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'single'],
    ]);

    Route::post('/upload/multiple', [
        'dispatch_route' => [\Test\Controller\UploadController::class, 'multiple'],
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

    Route::get('/v1/workflow/pause/tasks', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'pauseTasks'],
    ]);

    Route::get('/v1/workflow/run/events', [
        'dispatch_route' => [\Test\Module\Workflow\Controller\WorkflowController::class, 'events'],
    ]);

    Route::get('/v1/mcp/servers', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'servers'],
    ]);

    Route::get('/v1/mcp/servers/{id}/tools', [
        'dispatch_route' => [\Test\Module\Mcp\Controller\McpController::class, 'tools'],
    ]);

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
});

Route::match(['GET'],'/cache/test1', [
    'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
]);

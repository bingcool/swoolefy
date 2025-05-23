<?php

namespace Test\Router;

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
        $name = $requestInput->post('name');
    },

    'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

    'afterHandle' => function(RequestInput $requestInput) {

    },
    'afterHandle1' => function(RequestInput $requestInput) {

    },
]);

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

});

Route::match(['GET'],'/cache/test1', [
    'dispatch_route' => [\Test\Controller\CacheController::class, 'test1'],
]);

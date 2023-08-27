<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\Route;
use Test\Middleware\Route\ValidLoginMiddleware;

/**
 * Controller 下的控制器路由
 */

Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件
    'middleware' => [
        ValidLoginMiddleware::class
    ]
], function () {
    Route::get('/', [
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(Request $request) {
            var_dump('afterHandle');
        },
        'afterHandle1' => function(Request $request) {
            var_dump('afterHandle1');
        },
    ]);


    Route::get('/index/index', [
        'beforeHandle' => function(Request $request) {
            Context::set('name', 'bingcool');
            $name = Application::getApp()->getPostParams('name');
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(Request $request) {

        },
        'afterHandle1' => function(Request $request) {

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
        'dispatch_route' => [\Test\Controller\WsController::className(), 'test1'],
    ]);

});
<?php

namespace Test\Router;

use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\RequestInput;
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
        'beforeHandle' => function(RequestInput $requestInput) {
            $requestInput->setValue('name','黄增兵');
        },

        'beforeHandle1' => function(RequestInput $requestInput) {
            $name = $requestInput->getValue('name');
            var_dump($name);
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        },
        'afterHandle1' => function(RequestInput $requestInput) {
            var_dump('afterHandle1');
        },
    ]);


    Route::get('/index/index', [
        'beforeHandle' => function(RequestInput $requestInput) {
            Context::set('name', 'bingcool');
            $name = $requestInput->getPostParams('name');
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(RequestInput $requestInput) {

        },
        'afterHandle1' => function(RequestInput $requestInput) {

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
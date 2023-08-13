<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;

/**
 * Controller 下的控制器路由
 */

return [

    '/' => [
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
    ],

    '/index/index' => [
        'beforeHandle' => function(Request $request) {
            Context::set('name', 'bingcool');
            $name = Application::getApp()->getPostParams('name');
        },

        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        'afterHandle' => function(Request $request) {

        },
        'afterHandle1' => function(Request $request) {

        },
    ],

    '/index/testLog' => [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testLog'],
    ],

    '/token/jwt' => [
        'dispatch_route' => [\Test\Controller\TokenController::class, 'jwt'],
    ],


    '/getUuid' => [
        'dispatch_route' => [\Test\Controller\UuidController::class, 'getUuid'],
    ],

    '/lock-test1' => [
        'dispatch_route' => [\Test\Controller\LockController::class, 'locktest1'],
    ],

    '/rate-test1' => [
        'dispatch_route' => [\Test\Controller\RateLimitController::class, 'ratetest1'],
    ],

    '/validate-test1' => [
        'dispatch_route' => [\Test\Controller\ValidateController::class, 'test1'],
    ],

];
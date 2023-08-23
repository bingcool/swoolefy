<?php

namespace Test\Router;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Core\Coroutine\Context;
use Swoolefy\Http\Route;

/**
 * Module/Controller 下的控制器路由
 */

Route::group([
    // 路由前缀
    'prefix' => 'user',
    // 路由中间件
    'middleware' => []
], function () {

    Route::get('/user/testAddUser', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testAddUser'],
    ]);


    Route::get('/user/user-order/userList', [
        'beforeHandle' => function(Request $request) {
            $name = Application::getApp()->getRequestParams('name');
        },
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
    ]);

    Route::get('/user/user-order/save-order', [
        'beforeHandle' => function(Request $request) {
            $name = Application::getApp()->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);

    Route::get('/testTransactionAddOrder', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testTransactionAddOrder'],
    ]);

    Route::get('/order/list', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'list'],
    ]);


});
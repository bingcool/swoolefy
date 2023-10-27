<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
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

    Route::get('/testAddUser', [
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testAddUser'],
    ]);


    Route::get('/user-order/userList', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->input('name');
            var_dump($name);

            $orderIds = $requestInput->input('order_ids');
            var_dump($orderIds);

            $requestInput->getMethod();
        },
        'dispatch_route' => [\Test\Module\Order\Controller\UserOrderController::class, 'userList'],
    ]);

    Route::get('/user-order/save-order', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);

    Route::get('/user-order/update-order', [
        'beforeHandle' => function(RequestInput $requestInput) {
            $name = $requestInput->getRequestParams('name');
        },

        'dispatch_route' => [\Test\Controller\ObjectController::class, 'updateOrder'],
    ]);

    Route::get('/testTransactionAddOrder', [
        'before-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testTransactionAddOrder'],
        'after-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);

    Route::get('/order/list', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'list'],
    ]);

    Route::get('/order/add', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'saveOrder'],
    ]);


});
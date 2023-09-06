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
            $name = $requestInput->getValue('name');
            var_dump($name);
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

    Route::get('/testTransactionAddOrder', [
        'before-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
        'dispatch_route' => [\Test\Controller\IndexController::class, 'testTransactionAddOrder'],
        'after-validate' => \Test\Middleware\Route\ValidLoginMiddleware::class,
    ]);

    Route::get('/order/list', [
        'dispatch_route' => [\Test\Controller\ObjectController::class, 'list'],
    ]);


});
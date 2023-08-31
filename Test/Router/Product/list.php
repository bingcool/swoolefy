<?php

namespace Test\Router\Product;

use Swoolefy\Http\Route;
use Swoolefy\Http\RequestInput;

/**
 * Controller 下的控制器路由
 */

Route::group([
    // 路由前缀
    'prefix' => 'product',
    // 路由中间件
    'middleware' => []
], function () {
    Route::get('/list/mylist', [
        'beforeHandle' => function(RequestInput $requestInput) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
        'afterHandle' => function(RequestInput $requestInput) {
            var_dump('afterHandle');
        }
    ]);
});
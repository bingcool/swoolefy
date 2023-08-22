<?php

namespace Test\Router\Product;

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Http\Route;

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
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],
        'afterHandle' => function(Request $request) {
            var_dump('afterHandle');
        }
    ]);
});
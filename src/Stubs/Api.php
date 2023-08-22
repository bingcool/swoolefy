<?php

use Swoole\Http\Request;
use Swoolefy\Core\Application;
use Swoolefy\Http\Route;

Route::group([
    // 路由前缀
    'prefix' => 'api',
    // 路由中间件
    'middleware' => []
], function () {

    Route::get('/', [
        // 前置路由
        'beforeHandle' => function(Request $request) {
            var_dump('beforeHandle');
        },

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由
        'afterHandle' => function(Request $request) {
            var_dump('afterHandle');
        },

        'afterHandle1' => function(Request $request) {
            var_dump('afterHandle1');
        }
    ]);


    Route::get('/index/index', [
        // 前置路由
        'beforeHandle1' => function(Request $request) {
            $name = Application::getApp()->getPostParams('name');
        },

        'beforeHandle2' => function(Request $request) {
            $name = Application::getApp()->getPostParams('name');
        },

        // 控制器action
        'dispatch_route' => [\Test\Controller\IndexController::class, 'index'],

        // 后置路由1
        'afterHandle1' => function(Request $request) {

        },
        // 后置路由2
        'afterHandle2' => function(Request $request) {

        },
    ]);
});
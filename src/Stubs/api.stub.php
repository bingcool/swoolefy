<?php

use Swoolefy\Http\Middleware\AuthenticateMiddleware;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;

/**
 * @api api公共路由
 *
 * 鉴权：请对需要登录的路由挂 {@see AuthenticateMiddleware}（需 Config/auth.php + component/auth.php）。
 * 勿在整组 api 上强制鉴权，否则健康检查 / 公开接口也会 401。
 */

Route::group([
    'prefix' => 'api',
], function () {

    // 公开示例
    Route::get('/index/index', [
        'before_handle' => function (RequestInput $requestInput) {
            $requestInput->setValue('name', 'swoolefy');
        },
        'dispatch_route' => [\App\Controller\IndexController::class, 'index'],
    ]);

    // 需登录示例：Authorization: Bearer <jwt>
    Route::get('/me', [
        'beforeHandle' => AuthenticateMiddleware::class,
        'dispatch_route' => [\App\Controller\IndexController::class, 'index'],
    ]);
});

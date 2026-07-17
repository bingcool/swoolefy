<?php

namespace Test\Router;

use Swoolefy\Http\Middleware\AuthenticateMiddleware;
use Swoolefy\Http\Route;
use Test\Controller\AuthUserController;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * AuthUserController 路由：before 前置 AuthenticateMiddleware（强制登录）。
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/auth-user/me — 缺 Bearer → 401
    Route::get('/auth-user/me', [
        'beforeHandle' => AuthenticateMiddleware::class,
        'dispatch_route' => [AuthUserController::class, 'me'],
    ]);

});

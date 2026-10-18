<?php

declare(strict_types=1);

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Controller\ImageController;
use Test\Middleware\Group\GroupTestMiddleware;

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {
    Route::match(['GET', 'POST'], '/image/create', [
        'dispatch_route' => [ImageController::class, 'create'],
    ]);
    Route::match(['GET', 'POST'], '/image/preset', [
        'dispatch_route' => [ImageController::class, 'preset'],
    ]);
    Route::match(['GET', 'POST'], '/image/watermark', [
        'dispatch_route' => [ImageController::class, 'watermark'],
    ]);
    Route::get('/image/png', [
        'dispatch_route' => [ImageController::class, 'png'],
    ]);
});

<?php

declare(strict_types=1);

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Controller\QrcodeController;
use Test\Middleware\Group\GroupTestMiddleware;

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {
    Route::match(['GET', 'POST'], '/qrcode/png', [
        'dispatch_route' => [QrcodeController::class, 'png'],
    ]);
    Route::match(['GET', 'POST'], '/qrcode/svg', [
        'dispatch_route' => [QrcodeController::class, 'svg'],
    ]);
    Route::match(['GET', 'POST'], '/qrcode/wifi', [
        'dispatch_route' => [QrcodeController::class, 'wifi'],
    ]);
    Route::match(['GET', 'POST'], '/qrcode/logo', [
        'dispatch_route' => [QrcodeController::class, 'logo'],
    ]);
    Route::get('/qrcode/png-inline', [
        'dispatch_route' => [QrcodeController::class, 'pngInline'],
    ]);
});

<?php

declare(strict_types=1);

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Controller\MailController;
use Test\Middleware\Group\GroupTestMiddleware;

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {
    Route::match(['GET', 'POST'], '/mail/send', [
        'dispatch_route' => [MailController::class, 'send'],
    ]);
    Route::match(['GET', 'POST'], '/mail/template', [
        'dispatch_route' => [MailController::class, 'template'],
    ]);
    Route::match(['GET', 'POST'], '/mail/attachment', [
        'dispatch_route' => [MailController::class, 'attachment'],
    ]);
    Route::match(['GET', 'POST'], '/mail/invalid', [
        'dispatch_route' => [MailController::class, 'invalid'],
    ]);
    Route::get('/mail/config', [
        'dispatch_route' => [MailController::class, 'config'],
    ]);
});

<?php

namespace Test\Router;

use Swoolefy\Http\RequestInput;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * 本文件由 Api.php 拆分而来，按控制器模块维护。
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/amqp/publish
    Route::get('/amqp/publish', [
        'dispatch_route' => [\Test\Controller\AmqpController::class, 'testPublish'],
    ]);

    // GET /api/amqp/publish-delay-topic
    Route::get('/amqp/publish-delay-topic', [
        'dispatch_route' => [\Test\Controller\AmqpController::class, 'testPublish1'],
    ]);

    // GET /api/amqp/publish-delay-direct
    Route::get('/amqp/publish-delay-direct', [
        'dispatch_route' => [\Test\Controller\AmqpController::class, 'testPublish2'],
    ]);

});

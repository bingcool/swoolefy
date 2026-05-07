<?php

namespace Test\Router;

use Swoolefy\Http\Middleware\CorsMiddleware;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Module\Cron\Controller\CronTaskManagerController;

Route::group([
    'prefix' => 'api/v1',
    'middleware' => [
        CorsMiddleware::class,
        GroupTestMiddleware::class,
    ]
], function () {
    // 任务管理
    Route::get('/tasks', [
        'dispatch_route' => [CronTaskManagerController::class, 'listTasks'],
    ]);
    Route::post('/tasks', [
        'dispatch_route' => [CronTaskManagerController::class, 'createTask'],
    ]);
    Route::put('/tasks', [
        'dispatch_route' => [CronTaskManagerController::class, 'updateTask'],
    ]);
    Route::delete('/tasks', [
        'dispatch_route' => [CronTaskManagerController::class, 'deleteTask'],
    ]);
    Route::match(['POST', 'PUT'], '/tasks/status', [
        'dispatch_route' => [CronTaskManagerController::class, 'switchTaskStatus'],
    ]);

    // 节点管理
    Route::get('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'listNodes'],
    ]);
    Route::post('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'createNode'],
    ]);
    Route::delete('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'deleteNode'],
    ]);

    // 日志监控
    Route::get('/tasks/logs', [
        'dispatch_route' => [CronTaskManagerController::class, 'taskLogs'],
    ]);
    Route::get('/tasks/stats', [
        'dispatch_route' => [CronTaskManagerController::class, 'taskStats'],
    ]);

    // Agent节点拉取任务
    Route::get('/agent/tasks', [
        'dispatch_route' => [CronTaskManagerController::class, 'agentTasks'],
    ]);
    Route::post('/agent/heartbeat', [
        'dispatch_route' => [CronTaskManagerController::class, 'agentHeartbeat'],
    ]);
    Route::post('/agent/report', [
        'dispatch_route' => [CronTaskManagerController::class, 'agentReport'],
    ]);
});

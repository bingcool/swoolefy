<?php

namespace Test\Router;

use Swoolefy\Http\Middleware\CorsMiddleware;
use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;
use Test\Module\Cron\Controller\CronAdminController;
use Test\Module\Cron\Controller\CronTaskManagerController;

Route::get('/cron-admin', [
    'dispatch_route' => [CronAdminController::class, 'index'],
]);
Route::get('/cron-admin/index.html', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/task.html', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/execution.html', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/log.html', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/css/common.css', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/css/pages.css', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/common.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/app.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/dashboard.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/tasks.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/editor.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/detail.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/executions.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/nodes.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);
Route::get('/cron-admin/assets/js/runtime.js', [
    'dispatch_route' => [CronAdminController::class, 'assets'],
]);

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
    Route::put('/tasks/batch-status', [
        'dispatch_route' => [CronTaskManagerController::class, 'batchSwitchStatus'],
    ]);
    Route::get('/tasks/detail', [
        'dispatch_route' => [CronTaskManagerController::class, 'getTask'],
    ]);
    Route::post('/tasks/expression/preview', [
        'dispatch_route' => [CronTaskManagerController::class, 'previewExpression'],
    ]);
    Route::get('/tasks/execution', [
        'dispatch_route' => [CronTaskManagerController::class, 'getExecution'],
    ]);
    Route::post('/tasks/duplicate', [
        'dispatch_route' => [CronTaskManagerController::class, 'duplicateTask'],
    ]);
    Route::post('/tasks/run', [
        'dispatch_route' => [CronTaskManagerController::class, 'runTaskOnce'],
    ]);

    // 节点管理
    Route::get('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'listNodes'],
    ]);
    Route::post('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'createNode'],
    ]);
    Route::put('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'updateNode'],
    ]);
    Route::get('/nodes/detail', [
        'dispatch_route' => [CronTaskManagerController::class, 'getNode'],
    ]);
    Route::delete('/nodes', [
        'dispatch_route' => [CronTaskManagerController::class, 'deleteNode'],
    ]);

    // 节点分组
    Route::get('/node-groups', [
        'dispatch_route' => [CronTaskManagerController::class, 'listNodeGroups'],
    ]);
    Route::post('/node-groups', [
        'dispatch_route' => [CronTaskManagerController::class, 'createNodeGroup'],
    ]);
    Route::put('/node-groups', [
        'dispatch_route' => [CronTaskManagerController::class, 'updateNodeGroup'],
    ]);
    Route::get('/node-groups/detail', [
        'dispatch_route' => [CronTaskManagerController::class, 'getNodeGroup'],
    ]);
    Route::delete('/node-groups', [
        'dispatch_route' => [CronTaskManagerController::class, 'deleteNodeGroup'],
    ]);

    // 日志监控
    Route::get('/tasks/logs', [
        'dispatch_route' => [CronTaskManagerController::class, 'taskLogs'],
    ]);
    Route::get('/tasks/stats', [
        'dispatch_route' => [CronTaskManagerController::class, 'taskStats'],
    ]);

    // Dashboard / Runtime
    Route::get('/dashboard/overview', [
        'dispatch_route' => [CronTaskManagerController::class, 'dashboardOverview'],
    ]);
    Route::get('/dashboard/execution-trend', [
        'dispatch_route' => [CronTaskManagerController::class, 'executionTrend'],
    ]);
    Route::get('/runtime/overview', [
        'dispatch_route' => [CronTaskManagerController::class, 'runtimeOverview'],
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

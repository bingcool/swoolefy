<?php

declare(strict_types=1);

namespace Test\Router;

use Swoolefy\Http\Route;
use Test\Middleware\Group\GroupTestMiddleware;

/**
 * PhpOffice Excel 导出/导入联调路由。
 */

Route::group([
    'prefix' => 'api',
    'middleware' => [
        GroupTestMiddleware::class,
    ],
], function () {

    // GET /api/excel/verify PhpOffice Excel 导出端到端验证
    Route::get('/excel/verify', [
        'dispatch_route' => [\Test\Controller\ExcelController::class, 'excelverify'],
    ]);

    // GET /api/excel/import/verify PhpOffice Excel 导入端到端验证
    Route::get('/excel/import/verify', [
        'dispatch_route' => [\Test\Controller\ExcelController::class, 'excelimportverify'],
    ]);

});

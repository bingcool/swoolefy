<?php

declare(strict_types=1);

namespace Test\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\Common\ExcelImportVerify;
use Test\Common\ExcelVerify;

/**
 * PhpOffice Excel 导出/导入联调入口。
 *
 * Route: GET /api/excel/verify        导出
 *        GET /api/excel/import/verify 导入
 *
 * ```bash
 * curl -X GET 'http://127.0.0.1:9501/api/excel/verify' -H 'Accept: application/json'
 * curl -X GET 'http://127.0.0.1:9501/api/excel/import/verify' -H 'Accept: application/json'
 * ```
 */
class ExcelController extends BController
{
    /**
     * 8 个场景：大数据量、跨批次、中文名、业务异常清理、同名碰撞、
     * 同请求并发、多行表头合并、合计行与自定义筛选。
     */
    #[ApiOperation(description: 'PhpOffice Excel 导出端到端验证')]
    public function excelverify(): array
    {
        return ExcelVerify::run();
    }

    /**
     * 5 个场景：5000 行批量落库、表头不匹配与实例复用、协程并发导入、
     * 按列类型读取与归一化、空行跳过/保留。
     */
    #[ApiOperation(description: 'PhpOffice Excel 导入端到端验证')]
    public function excelimportverify(): array
    {
        return ExcelImportVerify::run();
    }
}

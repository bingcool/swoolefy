<?php

declare(strict_types=1);

namespace Test\Common;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelExport;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelExportException;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportContext;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelExportResult;
use Vtiful\Kernel\Excel;
use Vtiful\Kernel\Format;
use ZipArchive;

/**
 * PhpOffice Excel 导出端到端验证。仅在 Test 服务 Worker 内运行。
 *
 * 每次运行在 Storage/exports/<runId> 下隔离文件，结束后递归删除；
 * 单个场景失败只记录错误，不中断后续场景。
 */
final class ExcelVerify
{
    private string $runId = '';

    private string $rootDirectory = '';

    public static function run(): array
    {
        if (!extension_loaded('xlswriter') || !class_exists(Excel::class)) {
            return [
                'pass'  => false,
                'run'   => '',
                'cases' => [[
                    'name'  => 'extension',
                    'pass'  => false,
                    'error' => 'ext-xlswriter is not loaded',
                ]],
            ];
        }

        return (new self())->execute();
    }

    private function execute(): array
    {
        $this->runId = date('His') . bin2hex(random_bytes(2));
        $this->rootDirectory = APP_PATH . '/Storage/exports/' . $this->runId;

        if (!$this->ensureDirectory($this->rootDirectory)) {
            return [
                'pass'  => false,
                'run'   => $this->runId,
                'cases' => [[
                    'name'  => 'storage',
                    'pass'  => false,
                    'error' => 'Cannot create export directory: ' . $this->rootDirectory,
                ]],
            ];
        }

        $scenarios = [
            'export_10000_rows'          => 'caseExport10000Rows',
            'batch_boundaries'           => 'caseBatchBoundaries',
            'chinese_name'               => 'caseChineseName',
            'business_exception_cleanup' => 'caseBusinessExceptionCleanup',
            'collision_not_overwritten'  => 'caseCollisionNotOverwritten',
            'concurrent_exports'         => 'caseConcurrentExports',
            'multi_header_merge'         => 'caseMultiHeaderMerge',
            'total_row_custom_filter'    => 'caseTotalRowCustomFilter',
        ];

        $cases = [];
        foreach ($scenarios as $name => $method) {
            $startedAt = microtime(true);
            try {
                $detail = $this->{$method}();
                $cases[] = [
                    'name'       => $name,
                    'pass'       => true,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'detail'     => $detail,
                ];
            } catch (\Throwable $e) {
                $cases[] = [
                    'name'       => $name,
                    'pass'       => false,
                    'elapsed_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                    'error'      => get_class($e) . ': ' . $e->getMessage(),
                ];
            }
        }

        $cleanup = 'ok';
        try {
            $this->removeDirectory($this->rootDirectory);
        } catch (\Throwable $e) {
            $cleanup = get_class($e) . ': ' . $e->getMessage();
        }

        $pass = $cleanup === 'ok' && !in_array(false, array_column($cases, 'pass'), true);

        return [
            'pass'    => $pass,
            'run'     => $this->runId,
            'cleanup' => $cleanup,
            'cases'   => $cases,
        ];
    }

    private function caseExport10000Rows(): array
    {
        $directory = $this->caseDirectory('export_10000_rows');
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 10000; $i++) {
                yield ['NO-' . $i, $i, $i % 2 === 0 ? 'even' : 'odd'];
            }
        })();

        $result = $this->export($directory, ['编号', '序号', '奇偶'], $rows, [
            'fileName'   => 'rows_10000',
            'autoFilter' => false,
        ]);

        $this->assertTrue($result->rowCount === 10000, '10,000 行导出行数不正确：' . $result->rowCount);
        $this->assertTrue($result->size > 0, '导出文件为空');
        $this->assertZipValid($result->path);

        $data = $this->readRows($result->path);
        $this->assertTrue(count($data) === 10001, '回读行数不正确：' . count($data));
        $this->assertTrue($data[0] === ['编号', '序号', '奇偶'], '表头不正确');
        $this->assertTrue($data[1] === ['NO-1', 1, 'odd'], '首行数据不正确');
        $this->assertTrue($data[10000] === ['NO-10000', 10000, 'even'], '末行数据不正确');

        return ['rows' => $result->rowCount, 'size' => $result->size];
    }

    private function caseBatchBoundaries(): array
    {
        $directory = $this->caseDirectory('batch_boundaries');
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 2500; $i++) {
                yield ['ROW-' . $i, $i];
            }
        })();

        $result = $this->export($directory, ['编号', '序号'], $rows, [
            'fileName'   => 'batch_2500',
            'batchSize'  => 500,
            'autoFilter' => false,
        ]);

        $this->assertTrue($result->rowCount === 2500, '跨批次导出行数不正确');
        $data = $this->readRows($result->path);
        $this->assertTrue(count($data) === 2501, '跨批次回读行数不正确');
        $this->assertTrue($data[500] === ['ROW-500', 500], '第 500 行（批次边界）不正确');
        $this->assertTrue($data[501] === ['ROW-501', 501], '第 501 行（批次边界）不正确');
        $this->assertTrue($data[2500] === ['ROW-2500', 2500], '末行不正确');

        return ['rows' => $result->rowCount, 'batch_size' => 500];
    }

    private function caseChineseName(): array
    {
        $directory = $this->caseDirectory('chinese_name');
        $result = $this->export($directory, ['列一', '列二'], [['值', 2]], [
            'fileName'   => '订单导出_中文',
            'sheetName'  => '订单明细',
            'autoFilter' => false,
        ]);

        $this->assertTrue($result->fileName === '订单导出_中文.xlsx', '中文文件名不正确：' . $result->fileName);
        $this->assertTrue($result->sheetName === '订单明细', '中文 sheet 名不正确');
        $this->assertTrue(is_file($result->path), '中文文件名导出文件不存在');

        $data = $this->readRows($result->path, '订单明细');
        $this->assertTrue($data[0] === ['列一', '列二'], '中文表头回读不正确');
        $this->assertTrue($data[1] === ['值', 2], '中文数据回读不正确');

        return ['file_name' => $result->fileName, 'sheet_name' => $result->sheetName];
    }

    private function caseBusinessExceptionCleanup(): array
    {
        $directory = $this->caseDirectory('business_exception_cleanup');
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 1200; $i++) {
                yield ['R' . $i, $i];
            }
            throw new \RuntimeException('excel rows exploded');
        })();

        $caught = false;
        try {
            $this->export($directory, ['编号', '序号'], $rows, [
                'fileName'   => 'fail_case',
                'batchSize'  => 500,
                'autoFilter' => false,
            ]);
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage() === 'excel rows exploded';
        }

        $this->assertTrue($caught, '业务异常未原样透传');
        $this->assertTrue(!is_file($directory . '/fail_case.xlsx'), '业务异常后目标文件残留');
        $this->assertTrue($this->directoryFiles($directory) === [], '业务异常后目录仍有残留文件');

        return ['exception' => \RuntimeException::class, 'leftover' => 0];
    }

    private function caseCollisionNotOverwritten(): array
    {
        $directory = $this->caseDirectory('collision_not_overwritten');
        $first = $this->export($directory, ['a'], [[1]], [
            'fileName'   => 'collision',
            'autoFilter' => false,
        ]);
        $hash = hash_file('sha256', $first->path);

        $caught = false;
        try {
            $this->export($directory, ['a'], [[2]], [
                'fileName'   => 'collision',
                'autoFilter' => false,
            ]);
        } catch (InvalidExcelExportException $e) {
            $caught = str_contains($e->getMessage(), 'already exists');
        }

        $this->assertTrue($caught, '同名文件二次导出未失败');
        $this->assertTrue(hash_file('sha256', $first->path) === $hash, '同名导出覆盖了已有文件');
        $this->assertTrue($this->readRows($first->path)[1] === [1], '首次导出文件内容被破坏');

        return ['hash' => $hash];
    }

    private function caseConcurrentExports(): array
    {
        $directory = $this->caseDirectory('concurrent_exports');
        $channel = new Channel(2);
        $cids = [];

        foreach (['concurrent_a', 'concurrent_b'] as $label) {
            $cids[] = goApp(function ($event) use ($directory, $label, $channel): void {
                try {
                    $rows = (static function () use ($label): \Generator {
                        for ($i = 1; $i <= 500; $i++) {
                            if ($i % 100 === 0) {
                                Coroutine::sleep(0.001);
                            }
                            yield [$label . '-' . $i, $i];
                        }
                    })();

                    $result = (new ExcelVerifyExport(['编号', '序号'], $rows, [
                        'fileName'   => $label,
                        'autoFilter' => false,
                    ]))->export($directory);

                    $channel->push([
                        'label'     => $label,
                        'path'      => $result->path,
                        'row_count' => $result->rowCount,
                    ]);
                } catch (\Throwable $e) {
                    $channel->push([
                        'label' => $label,
                        'error' => get_class($e) . ': ' . $e->getMessage(),
                    ]);
                }
            });
        }

        Coroutine::join($cids, 20.0);

        $items = [];
        $paths = [];
        for ($i = 0; $i < 2; $i++) {
            $item = $channel->pop(1.0);
            $this->assertTrue(is_array($item), '并发导出结果缺失');
            if (isset($item['error'])) {
                throw new \RuntimeException($item['error']);
            }
            $items[] = $item;
            $paths[] = $item['path'];
        }

        $this->assertTrue(count(array_unique($paths)) === 2, '并发导出产生相同路径');
        foreach ($items as $item) {
            $this->assertTrue($item['row_count'] === 500, '并发导出行数不正确');
            $this->assertZipValid($item['path']);
            $data = $this->readRows($item['path']);
            $this->assertTrue(count($data) === 501, '并发导出回读行数不正确');
            $this->assertTrue($data[1] === [$item['label'] . '-1', 1], '并发导出首行交叉');
            $this->assertTrue($data[500] === [$item['label'] . '-500', 500], '并发导出末行交叉');
        }

        return ['files' => count($items), 'rows_each' => 500];
    }

    private function caseMultiHeaderMerge(): array
    {
        $directory = $this->caseDirectory('multi_header_merge');
        $result = $this->export($directory, ['订单号', '金额', '状态', '时间'], [
            ['A1', 10, 'ok', '2026-10-22'],
            ['A2', 20, 'ok', '2026-10-22'],
        ], [
            'fileName'       => 'multi_header',
            'freezeRowCount' => 2,
            'autoFilter'     => false,
            'writeHeader'    => static function (Excel $excel, array $headers, $headerFormat): int {
                $excel->header($headers, $headerFormat);
                $note = new Format($excel->getHandle());
                $note->italic()->align(Format::FORMAT_ALIGN_CENTER);
                $excel->mergeCells('A2:D2', '金额单位：元', $note->toResource());

                return 2;
            },
        ]);

        $xml = $this->sheetXml($result->path);
        $this->assertTrue(str_contains($xml, '<mergeCell ref="A2:D2"/>'), '多行表头合并范围不正确');
        $this->assertTrue(str_contains($xml, '<pane ySplit="2"'), '冻结行数未按 2 行表头设置');

        $data = $this->readRows($result->path);
        $this->assertTrue($data[1][0] === '金额单位：元', '合并说明行位置不正确');
        $this->assertTrue($data[2] === ['A1', 10, 'ok', '2026-10-22'], '多行表头后数据起始行错位');
        $this->assertTrue($data[3] === ['A2', 20, 'ok', '2026-10-22'], '多行表头后第二行数据错位');

        return ['header_rows' => 2, 'data_start_row' => 3, 'merge' => 'A2:D2'];
    }

    private function caseTotalRowCustomFilter(): array
    {
        $directory = $this->caseDirectory('total_row_custom_filter');
        // constMemory 下 autoFilter 结束行必须是最后写入行，排除合计行需使用普通模式
        $result = $this->export($directory, ['组', '值'], [['G1', 1], ['G1', 2], ['G2', 3]], [
            'fileName'        => 'total_row',
            'useConstMemory'  => false,
            'autoFilter'      => true,
            'autoFilterRange' => 'A1:B4',
            'afterRows'       => static function (Excel $excel, ExcelExportContext $context): void {
                $totalRow = $context->nextRowIndex();
                $excel->insertText($totalRow, 0, '合计');
                $excel->insertText($totalRow, 1, 6);
            },
        ]);

        $xml = $this->sheetXml($result->path);
        $this->assertTrue(str_contains($xml, '<autoFilter ref="A1:B4"/>'), '自定义筛选范围不正确');

        $data = $this->readRows($result->path);
        $this->assertTrue(count($data) === 5, '合计行回读行数不正确');
        $this->assertTrue($data[4] === ['合计', 6], '合计行位置或内容不正确');
        $this->assertTrue($result->rowCount === 3, '结果行数不应包含合计行');

        return ['row_count' => $result->rowCount, 'filter' => 'A1:B4', 'total_row' => 5];
    }

    private function export(string $directory, array $headers, iterable $rows, array $options): ExcelExportResult
    {
        return (new ExcelVerifyExport($headers, $rows, $options))->export($directory);
    }

    /**
     * @return list<array>
     */
    private function readRows(string $path, string $sheetName = 'Sheet1'): array
    {
        $excel = (new Excel(['path' => dirname($path)]))
            ->openFile(basename($path))
            ->openSheet($sheetName);
        $data = $excel->getSheetData();
        $excel->close();

        return $data;
    }

    private function sheetXml(string $path): string
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, '无法打开 xlsx：' . $path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertTrue(is_string($xml), 'xlsx 缺少 sheet1.xml');

        return $xml;
    }

    /**
     * 逐 entry 解压，校验 zip 结构与数据完整性。
     */
    private function assertZipValid(string $path): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, '无法打开 xlsx：' . $path);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertTrue($zip->getFromIndex($i) !== false, 'xlsx 压缩包 entry 损坏：' . $i);
        }

        $zip->close();
    }

    private function caseDirectory(string $name): string
    {
        $directory = $this->rootDirectory . '/' . $name;
        $this->assertTrue($this->ensureDirectory($directory), '无法创建场景目录：' . $directory);

        return $directory;
    }

    private function ensureDirectory(string $directory): bool
    {
        if (is_dir($directory)) {
            return is_writable($directory);
        }

        return mkdir($directory, 0777, true) || (is_dir($directory) && is_writable($directory));
    }

    /**
     * @return list<string>
     */
    private function directoryFiles(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
}

/**
 * 可配置验证夹具：闭包覆写声明式钩子与生命周期钩子。
 */
final class ExcelVerifyExport extends AbstractExcelExport
{
    public function __construct(
        private readonly array $headers,
        private readonly iterable $rows,
        private readonly array $options = [],
    ) {
    }

    protected function headers(): array
    {
        return $this->headers;
    }

    protected function rows(): iterable
    {
        yield from $this->rows;
    }

    protected function fileName(): string
    {
        return $this->options['fileName'] ?? 'verify_' . bin2hex(random_bytes(4));
    }

    protected function sheetName(): string
    {
        return $this->options['sheetName'] ?? 'Sheet1';
    }

    protected function columnWidths(): array
    {
        return $this->options['columnWidths'] ?? [];
    }

    protected function freezeRowCount(): int
    {
        return $this->options['freezeRowCount'] ?? 1;
    }

    protected function autoFilter(): bool
    {
        return $this->options['autoFilter'] ?? true;
    }

    protected function useConstMemory(): bool
    {
        return $this->options['useConstMemory'] ?? true;
    }

    protected function batchSize(): int
    {
        return $this->options['batchSize'] ?? 1000;
    }

    protected function writeHeader(Excel $excel, array $headers, $headerFormat): int
    {
        if (isset($this->options['writeHeader'])) {
            return ($this->options['writeHeader'])($excel, $headers, $headerFormat);
        }

        return parent::writeHeader($excel, $headers, $headerFormat);
    }

    protected function afterRows(Excel $excel, ExcelExportContext $context): void
    {
        if (isset($this->options['afterRows'])) {
            ($this->options['afterRows'])($excel, $context);
        }
    }

    protected function autoFilterRange(ExcelExportContext $context): ?string
    {
        if (array_key_exists('autoFilterRange', $this->options)) {
            return $this->options['autoFilterRange'];
        }

        return parent::autoFilterRange($context);
    }
}

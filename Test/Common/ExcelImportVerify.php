<?php

declare(strict_types=1);

namespace Test\Common;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoolefy\Library\PhpOffice\Excel\AbstractExcelImport;
use Swoolefy\Library\PhpOffice\Excel\Exception\ExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\Exception\InvalidExcelImportException;
use Swoolefy\Library\PhpOffice\Excel\ValueObject\ExcelImportRow;
use Vtiful\Kernel\Excel;

/**
 * PhpOffice Excel 导入端到端验证。仅在 Test 服务 Worker 内运行。
 *
 * 每次运行在 Storage/imports/<runId> 下隔离文件，结束后递归删除；
 * 单个场景失败只记录错误，不中断后续场景。
 */
final class ExcelImportVerify
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
        $this->rootDirectory = APP_PATH . '/Storage/imports/' . $this->runId;

        if (!$this->ensureDirectory($this->rootDirectory)) {
            return [
                'pass'  => false,
                'run'   => $this->runId,
                'cases' => [[
                    'name'  => 'storage',
                    'pass'  => false,
                    'error' => 'Cannot create import directory: ' . $this->rootDirectory,
                ]],
            ];
        }

        $scenarios = [
            'import_5000_rows_batched'  => 'caseImport5000Rows',
            'header_mismatch_and_reuse' => 'caseHeaderMismatchAndReuse',
            'concurrent_imports'        => 'caseConcurrentImports',
            'typed_cells_and_normalize' => 'caseTypedCellsAndNormalize',
            'empty_rows'                => 'caseEmptyRows',
            'read_all_rows'             => 'caseReadAllRows',
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

    /**
     * 模拟批量落库：handleRow 攒批，afterImport 兜底 flush，校验无重复无丢失。
     */
    private function caseImport5000Rows(): array
    {
        $directory = $this->caseDirectory('import_5000_rows');
        $path = $this->createWorkbook(
            $directory,
            'rows_5000.xlsx',
            '数据',
            ['编号', '名称', '金额'],
            (static function (): \Generator {
                for ($i = 1; $i <= 5000; $i++) {
                    yield [sprintf('%05d', $i), '名称' . $i, $i * 1.5];
                }
            })()
        );

        $inserted = 0;
        $seen = [];
        $first = null;
        $last = null;
        $batch = [];
        $import = new ExcelVerifyImport([
            'handleRow' => static function (ExcelImportRow $row) use (&$inserted, &$seen, &$first, &$last, &$batch): void {
                $batch[] = $row->values;
                if (count($batch) >= 500) {
                    $inserted += count($batch);
                    $batch = [];
                }

                $id = $row->value('编号');
                if (isset($seen[$id])) {
                    throw new \RuntimeException('重复编号：' . $id);
                }
                $seen[$id] = true;

                $first ??= $id;
                $last = $id;
            },
            'afterImport' => static function (int $rowCount) use (&$inserted, &$batch): void {
                $inserted += count($batch);
                $batch = [];
            },
        ]);

        $result = $import->import($path);

        $this->assertTrue($result->rowCount === 5000, '导入行数不正确：' . $result->rowCount);
        $this->assertTrue($inserted === 5000, '落库行数不正确：' . $inserted);
        $this->assertTrue(count($seen) === 5000, '模拟落库存在重复或丢失');
        $this->assertTrue($result->headers === ['编号', '名称', '金额'], '表头映射不正确');
        $this->assertTrue($result->sheetName === '数据', 'sheet 名不正确');
        $this->assertTrue($first === '00001', '首行前导零丢失：' . var_export($first, true));
        $this->assertTrue($last === '05000', '末行前导零丢失：' . var_export($last, true));
        $this->assertTrue($result->lastRowNumber === 5001, '最后读取行号不正确');

        return ['rows' => $result->rowCount, 'inserted' => $inserted, 'sheets' => 1];
    }

    private function caseHeaderMismatchAndReuse(): array
    {
        $directory = $this->caseDirectory('header_mismatch');
        $path = $this->createWorkbook(
            $directory,
            'mismatch.xlsx',
            'S',
            ['编号', '名称'],
            [['A1', 'x']]
        );

        $import = new ExcelVerifyImport(['expectedHeaders' => ['编号', '姓名']]);

        $mismatchCaught = false;
        try {
            $import->import($path);
        } catch (InvalidExcelImportException $e) {
            $mismatchCaught = str_contains($e->getMessage(), 'header mismatch');
        }
        $this->assertTrue($mismatchCaught, '表头不匹配未被拦截');
        $this->assertTrue($import->afterCounts === [], '表头不匹配仍执行了 afterImport');

        $reuseBlocked = false;
        try {
            $import->import($path);
        } catch (ExcelImportException $e) {
            $reuseBlocked = str_contains($e->getMessage(), 'already been imported');
        }
        $this->assertTrue($reuseBlocked, '失败后导入实例可被复用');

        return ['exception' => InvalidExcelImportException::class, 'reuse_blocked' => true];
    }

    private function caseConcurrentImports(): array
    {
        $directory = $this->caseDirectory('concurrent_imports');
        $sizes = ['a' => 500, 'b' => 700];
        $files = [];

        foreach ($sizes as $label => $count) {
            $files[$label] = $this->createWorkbook(
                $directory,
                $label . '.xlsx',
                'S',
                ['编号', '值'],
                (static function () use ($label, $count): \Generator {
                    for ($i = 1; $i <= $count; $i++) {
                        yield [$label . '-' . $i, $i];
                    }
                })()
            );
        }

        $channel = new Channel(4);
        $cids = [];

        foreach ([['a', 1], ['a', 2], ['b', 1], ['b', 2]] as [$label, $repeat]) {
            $cids[] = goApp(function ($event) use ($files, $label, $repeat, $channel): void {
                try {
                    $handled = 0;
                    $first = null;
                    $last = null;
                    $import = new ExcelVerifyImport([
                        'handleRow' => static function (ExcelImportRow $row) use (&$handled, &$first, &$last, $label): void {
                            $handled++;
                            if ($handled % 100 === 0) {
                                Coroutine::sleep(0.001);
                            }

                            if ($row->value('编号') !== $label . '-' . $handled) {
                                throw new \RuntimeException(sprintf(
                                    '并发导入串行错乱：期望 %s-%d，实际 %s',
                                    $label,
                                    $handled,
                                    (string) $row->value('编号')
                                ));
                            }

                            $first ??= $row->value('编号');
                            $last = $row->value('编号');
                        },
                    ]);

                    $result = $import->import($files[$label]);
                    $channel->push([
                        'label'     => $label,
                        'repeat'    => $repeat,
                        'row_count' => $result->rowCount,
                        'first'     => $first,
                        'last'      => $last,
                    ]);
                } catch (\Throwable $e) {
                    $channel->push([
                        'label' => $label,
                        'error' => get_class($e) . ': ' . $e->getMessage(),
                    ]);
                }
            });
        }

        Coroutine::join($cids, 30.0);

        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $item = $channel->pop(1.0);
            $this->assertTrue(is_array($item), '并发导入结果缺失');
            if (isset($item['error'])) {
                throw new \RuntimeException($item['error']);
            }
            $items[] = $item;
        }

        foreach ($items as $item) {
            $expected = $sizes[$item['label']];
            $this->assertTrue($item['row_count'] === $expected, '并发导入行数不正确');
            $this->assertTrue($item['first'] === $item['label'] . '-1', '并发导入首行不正确');
            $this->assertTrue(
                $item['last'] === $item['label'] . '-' . $expected,
                '并发导入末行不正确'
            );
        }

        return ['coroutines' => count($items), 'rows_each' => $sizes];
    }

    private function caseTypedCellsAndNormalize(): array
    {
        $directory = $this->caseDirectory('typed_cells');
        $timestamp = 1792653600;
        $path = $this->createWorkbook(
            $directory,
            'typed.xlsx',
            'S',
            ['编号', '金额', '状态', '日期'],
            [
                ['00123', 12.5, ' 已支付 '],
                ['A002', 3, ' 待发货 '],
            ],
            [
                [1, 3, $timestamp],
                [2, 3, $timestamp + 86400],
            ]
        );

        $import = new ExcelVerifyImport([
            'cellTypes' => [
                0 => Excel::TYPE_STRING,
                1 => Excel::TYPE_DOUBLE,
                3 => Excel::TYPE_TIMESTAMP,
            ],
            'normalizeCells' => static function (array $cells): array {
                return array_map(
                    static fn ($value) => is_string($value) ? trim($value) : $value,
                    $cells
                );
            },
        ]);

        $result = $import->import($path);

        $this->assertTrue($result->rowCount === 2, '类型导入行数不正确');
        $this->assertTrue($import->rows[0]->cells === ['00123', 12.5, '已支付', $timestamp], '首行类型或归一化不正确');
        $this->assertTrue($import->rows[1]->cells === ['A002', 3.0, '待发货', $timestamp + 86400], '次行类型或归一化不正确');
        $this->assertTrue($import->rows[0]->value('状态') === '已支付', '表头映射值不正确');

        return ['timestamp' => $timestamp, 'columns' => 4];
    }

    private function caseEmptyRows(): array
    {
        $directory = $this->caseDirectory('empty_rows');
        $path = $this->createWorkbook(
            $directory,
            'empty.xlsx',
            'S',
            ['编号', '名称'],
            [
                ['A1', 'x'],
                [],
                ['   ', '   '],
                ['A2', 'y'],
            ]
        );

        $skipping = new ExcelVerifyImport();
        $result = $skipping->import($path);

        $this->assertTrue($result->rowCount === 2, '跳过空行后的行数不正确');
        $this->assertTrue($result->skippedRowCount === 2, '空行跳过计数不正确');
        $this->assertTrue($result->lastRowNumber === 5, '最后读取行号不正确');

        $keeping = new ExcelVerifyImport(['skipEmptyRows' => false]);
        $result = $keeping->import($path);

        $this->assertTrue($result->rowCount === 4, '保留空行后的行数不正确');
        $this->assertTrue($result->skippedRowCount === 0, '保留空行时不应统计跳过');
        $this->assertTrue($keeping->rows[1]->isEmpty(), '空行未被标记为空');
        $this->assertTrue($keeping->rows[2]->isEmpty(), '空白行未被标记为空');

        return ['rows' => 2, 'skipped' => 2];
    }

    /**
     * read() 一次返回全部行，不触发生命周期钩子；只读子类无需实现 handleRow()。
     */
    private function caseReadAllRows(): array
    {
        $directory = $this->caseDirectory('read_all');
        $path = $this->createWorkbook(
            $directory,
            'read_all.xlsx',
            'S',
            ['编号', '名称', '金额'],
            (static function (): \Generator {
                for ($i = 1; $i <= 500; $i++) {
                    yield [sprintf('%05d', $i), '名称' . $i, $i];
                }
            })()
        );

        $import = new ExcelVerifyImport();
        $result = $import->read($path);

        $this->assertTrue($result->rowCount === 500, 'read() 行数不正确：' . $result->rowCount);
        $this->assertTrue(count($result->rows) === 500, 'read() 行对象数量不正确');
        $this->assertTrue($result->headers === ['编号', '名称', '金额'], 'read() 表头映射不正确');
        $this->assertTrue($result->rows[0]->value('编号') === '00001', 'read() 首行前导零丢失');
        $this->assertTrue($result->rows[499]->value('编号') === '00500', 'read() 末行数据不正确');
        $this->assertTrue($result->lastRowNumber === 501, 'read() 最后行号不正确');
        $this->assertTrue($import->rows === [], 'read() 不应调用 handleRow()');
        $this->assertTrue($import->afterCounts === [], 'read() 不应调用 afterImport()');

        $readOnly = new ExcelVerifyRead();
        $readOnlyResult = $readOnly->read($path);
        $this->assertTrue($readOnlyResult->rowCount === 500, '只读子类未实现 handleRow() 时 read() 失败');

        $reuseBlocked = false;
        try {
            $import->import($path);
        } catch (ExcelImportException $e) {
            $reuseBlocked = str_contains($e->getMessage(), 'already been imported or read');
        }
        $this->assertTrue($reuseBlocked, 'read() 后 import() 未被拒绝');

        return ['rows' => $result->rowCount, 'read_only_subclass' => true];
    }

    /**
     * @param list<string|int|float> $headers
     * @param iterable<array<mixed>> $rows
     * @param list<array{0: int, 1: int, 2: int}> $dates
     */
    private function createWorkbook(
        string $directory,
        string $fileName,
        string $sheetName,
        array $headers,
        iterable $rows,
        array $dates = []
    ): string {
        $excel = new Excel(['path' => $directory]);
        $excel->fileName($fileName, $sheetName);
        $excel->header($headers);

        $batch = [];
        foreach ($rows as $row) {
            $batch[] = $row;
            if (count($batch) >= 500) {
                $excel->data($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $excel->data($batch);
        }

        foreach ($dates as [$rowIndex, $columnIndex, $timestamp]) {
            $excel->insertDate($rowIndex, $columnIndex, $timestamp, 'yyyy-mm-dd hh:mm:ss');
        }

        $path = $excel->output();
        $excel->close();

        return $path;
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
final class ExcelVerifyImport extends AbstractExcelImport
{
    /** @var list<ExcelImportRow> */
    public array $rows = [];

    /** @var list<int> */
    public array $afterCounts = [];

    public function __construct(private readonly array $options = [])
    {
    }

    protected function handleRow(ExcelImportRow $row): void
    {
        if (isset($this->options['handleRow'])) {
            ($this->options['handleRow'])($row);

            return;
        }

        $this->rows[] = $row;
    }

    protected function sheetName(): ?string
    {
        return $this->options['sheetName'] ?? null;
    }

    protected function headerRow(): int
    {
        return $this->options['headerRow'] ?? 1;
    }

    protected function dataStartRow(): int
    {
        return $this->options['dataStartRow'] ?? 0;
    }

    protected function expectedHeaders(): ?array
    {
        return $this->options['expectedHeaders'] ?? null;
    }

    protected function useStringType(): bool
    {
        return $this->options['useStringType'] ?? true;
    }

    protected function cellTypes(): array
    {
        return $this->options['cellTypes'] ?? [];
    }

    protected function skipEmptyRows(): bool
    {
        return $this->options['skipEmptyRows'] ?? true;
    }

    protected function normalizeCells(array $cells, int $rowNumber): array
    {
        $hook = $this->options['normalizeCells'] ?? null;

        return $hook === null ? $cells : $hook($cells, $rowNumber);
    }

    protected function afterImport(int $rowCount): void
    {
        $this->afterCounts[] = $rowCount;

        if (isset($this->options['afterImport'])) {
            ($this->options['afterImport'])($rowCount);
        }
    }
}

/**
 * 只读验证夹具：不实现 handleRow()，验证 read() 可独立使用。
 */
final class ExcelVerifyRead extends AbstractExcelImport
{
}

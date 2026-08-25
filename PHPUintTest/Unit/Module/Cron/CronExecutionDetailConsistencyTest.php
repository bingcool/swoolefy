<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use Swoolefy\Http\RequestValidate;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailQueryDto;
use Test\Module\Cron\Request\CronTaskManager\ExecutionDetailRequest;
use Test\Module\Cron\Service\CronTaskManagerService;

final class CronExecutionDetailConsistencyTest extends TestCase
{
    public function testExecutionDetailQueryDtoCarriesLogIdAndBatch(): void
    {
        $dto = ExecutionDetailQueryDto::of(12, '  batch-001  ', 99);

        $this->assertSame(12, $dto->getTaskId());
        $this->assertSame('batch-001', $dto->getExecBatchId());
        $this->assertSame(99, $dto->getLogId());
    }

    public function testExecutionDetailRequestSupportsLogIdStringToInt(): void
    {
        $params = [
            'id' => '12',
            'execBatchId' => 'batch-001',
            'logId' => '99',
        ];
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'GET',
            '/api/v1/tasks/execution',
            $params,
        ));
        $validate->applyStringToIntCoercion($params, ExecutionDetailRequest::class);

        $this->assertSame(12, $params['id']);
        $this->assertSame(99, $params['logId']);
        $this->assertIsInt($params['logId']);
    }

    public function testServiceExecutionLookupPrefersLogIdAndKeepsBatchFallback(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );

        $this->assertStringContainsString('$query->getLogId()', $src);
        $this->assertStringContainsString("->where('id', \$logId)->find()", $src);
        $this->assertStringContainsString("'cron_id' => \$taskId", $src);
        $this->assertStringContainsString("'exec_batch_id' => \$execBatchId", $src);
    }

    public function testServiceReturnsExplicitErrorWhenExecutionMissing(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(CronTaskManagerService::class))->getFileName()
        );

        $this->assertStringContainsString("throw CronTaskException::throw('执行记录不存在', -1);", $src);
    }

    public function testExecutionDetailDtoSupportsCamelCaseRowKeys(): void
    {
        $dto = ExecutionDetailDto::fromLogRow([
            'id' => 6843,
            'cronId' => 6,
            'execBatchId' => '82592af1381e8e10',
            'status' => 2,
            'triggerType' => 2,
            'durationMs' => 128,
            'taskItem' => ['cron_name' => 'demo-task'],
            'createdAt' => '2026-08-25 12:00:00',
            'updatedAt' => '2026-08-25 12:00:01',
            'message' => 'ok',
        ]);

        $this->assertSame(6, $dto->getTaskId());
        $this->assertSame('82592af1381e8e10', $dto->getExecBatchId());
        $this->assertSame('success', $dto->getStatus());
        $this->assertSame(128.0, $dto->getDurationMs());
    }
}


<?php

declare(strict_types=1);

namespace PHPUintTest\Unit\Module\Cron;

use PHPUintTest\Support\HttpRequestHarness;
use PHPUintTest\TestCase;
use Swoolefy\Http\RequestValidate;
use Test\Module\Cron\Dto\CronTaskManager\CronAgentNodeRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailDto;
use Test\Module\Cron\Dto\CronTaskManager\ExpressionPreviewDto;
use Test\Module\Cron\Dto\CronTaskManager\ListTasksQueryDto;
use Test\Module\Cron\Request\CronTaskManager\ListTasksRequest;
use Test\Module\Cron\Request\CronTaskManager\TaskLogsQueryRequest;
use Test\Module\Cron\Service\CronTaskManagerService;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;

/**
 * Admin 展示层 DTO / 表达式预览（不依赖 DB）。
 */
final class CronAdminDtoTest extends TestCase
{
    public function testRowMapsRetryAndExpressionType(): void
    {
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 1,
            'node_id' => 2,
            'name' => 't',
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
            'retry' => 3,
            'http_headers' => ['Authorization' => 'secret', 'X-Demo' => '1'],
        ], 1000);

        $this->assertSame(3, $dto->getRetry());
        $this->assertSame('interval', $dto->getExpressionType());
        $this->assertSame('******', $dto->getHttpHeaders()['Authorization'] ?? null);
        $this->assertSame('1', $dto->getHttpHeaders()['X-Demo'] ?? null);
        $this->assertSame(1005, $dto->getNextRunAt());
        $this->assertNotSame('', $dto->getNextRunAtAt());
    }

    public function testRowNextRunAtDisabledIsNull(): void
    {
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 1,
            'cron_name' => 'paused',
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
            'status' => 0,
        ], 1000);

        $this->assertNull($dto->getNextRunAt());
        $this->assertSame('', $dto->getNextRunAtAt());
    }

    public function testRowNextRunAtInvalidExpressionDoesNotThrow(): void
    {
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 1,
            'cron_name' => 'bad-expr',
            'expression' => '???',
            'command' => 'x',
            'exec_type' => 1,
            'status' => 1,
        ], 1000);

        $this->assertNull($dto->getNextRunAt());
        $this->assertSame('', $dto->getNextRunAtAt());
    }

    public function testRowNextRunAtCronExpression(): void
    {
        $from = (new \DateTimeImmutable('2026-08-15 10:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $expected = (new \DateTimeImmutable('2026-08-15 10:05:00', new \DateTimeZone('UTC')))->getTimestamp();
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 1,
            'cron_name' => 'linux-cron',
            'expression' => '*/5 * * * *',
            'command' => 'x',
            'exec_type' => 1,
            'status' => 1,
            'timezone' => 'UTC',
        ], $from);

        $this->assertSame('cron', $dto->getExpressionType());
        $this->assertSame($expected, $dto->getNextRunAt());
        $this->assertSame(date('Y-m-d H:i:s', $expected), $dto->getNextRunAtAt());
    }

    public function testRowMapsCronNameColumn(): void
    {
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 7,
            'node_id' => 1786977160,
            'cron_name' => 'shell-1',
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
        ]);

        $this->assertSame('shell-1', $dto->getName());
        $this->assertSame(1786977160, $dto->getNodeId());
        $this->assertSame(0, $dto->getRetry());
    }

    public function testRowRetryDefaultsToZeroWhenMissing(): void
    {
        $dto = CronTaskRowDto::fromEntityRow([
            'id' => 1,
            'cron_name' => 'legacy-row',
            'expression' => '15',
            'command' => 'x',
            'exec_type' => 1,
        ]);

        $this->assertSame(0, $dto->getRetry());
    }

    public function testListTasksQueryFromStringQueryParams(): void
    {
        $params = [
            'page' => '1',
            'pageSize' => '20',
            'status' => '1',
            'nodeId' => '1786977160',
        ];
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'GET',
            '/api/v1/tasks',
            $params,
        ));
        $validate->applyStringToIntCoercion($params, ListTasksRequest::class);

        $this->assertSame(1, $params['page']);
        $this->assertSame(20, $params['pageSize']);
        $this->assertSame(1, $params['status']);
        $this->assertSame(1786977160, $params['nodeId']);
        $this->assertIsInt($params['nodeId']);

        $request = (new ListTasksRequest())
            ->setPage($params['page'])
            ->setPageSize($params['pageSize'])
            ->setStatus($params['status'])
            ->setNodeId($params['nodeId']);

        $query = (new ListTasksQueryDto())
            ->setPage($request->getPage())
            ->setPageSize($request->getPageSize())
            ->setStatus($request->getStatus())
            ->setNodeId($request->getNodeId());

        $this->assertSame(1, $query->getPage());
        $this->assertSame(20, $query->getPageSize());
        $this->assertSame(1, $query->getStatus());
        $this->assertSame(1786977160, $query->getNodeId());
        $this->assertSame(0, $query->getOffset());
    }

    public function testTaskLogsQueryOptionalTaskId(): void
    {
        $validate = new RequestValidate(HttpRequestHarness::requestInput(
            'GET',
            '/api/v1/tasks/logs',
            ['page' => '1', 'pageSize' => '20'],
        ));
        $params = ['page' => '1', 'pageSize' => '20'];
        $validate->applyStringToIntCoercion($params, TaskLogsQueryRequest::class);

        $this->assertArrayNotHasKey('taskId', $params);

        $request = (new TaskLogsQueryRequest())
            ->setPage($params['page'])
            ->setPageSize($params['pageSize']);

        $query = (new TaskLogsQueryDto())
            ->setTaskId($request->getTaskId())
            ->setPage($request->getPage())
            ->setPageSize($request->getPageSize());

        $this->assertNull($query->getTaskId());
        $this->assertSame(1, $query->getPage());
        $this->assertSame(20, $query->getPageSize());

        $paramsWithTask = [
            'page' => '1',
            'pageSize' => '20',
            'taskId' => '7',
            'taskName' => '  sync  ',
            'triggerType' => '2',
        ];
        $validate->applyStringToIntCoercion($paramsWithTask, TaskLogsQueryRequest::class);
        $this->assertSame(7, $paramsWithTask['taskId']);
        $this->assertSame(2, $paramsWithTask['triggerType']);

        $requestWithTask = (new TaskLogsQueryRequest())
            ->setTaskId($paramsWithTask['taskId'])
            ->setTaskName($paramsWithTask['taskName'])
            ->setTriggerType($paramsWithTask['triggerType'])
            ->setPage(1)
            ->setPageSize(20);

        $filtered = (new TaskLogsQueryDto())
            ->setTaskId($requestWithTask->getTaskId())
            ->setTaskName($requestWithTask->getTaskName())
            ->setTriggerType($requestWithTask->getTriggerType())
            ->setPage($requestWithTask->getPage())
            ->setPageSize($requestWithTask->getPageSize());
        $this->assertSame(7, $filtered->getTaskId());
        $this->assertSame('sync', $filtered->getTaskName());
        $this->assertSame(2, $filtered->getTriggerType());
    }

    public function testLinuxCronExpressionType(): void
    {
        $this->assertSame('cron', CronTaskRowDto::deriveExpressionType('*/5 * * * *'));
        $this->assertSame('interval', CronTaskRowDto::deriveExpressionType('20'));
    }

    public function testClassifyMessage(): void
    {
        $this->assertSame('success', ExecutionDetailDto::classifyMessage('【job】SUCCESS duration=12ms'));
        $this->assertSame('failed', ExecutionDetailDto::classifyMessage('【job】FAILED error=1'));
        $this->assertSame('skipped', ExecutionDetailDto::classifyMessage('【job】SKIP 命中 cron_skip'));
        $this->assertSame('unknown', ExecutionDetailDto::classifyMessage('执行失败？'), '无法确认的历史文案不得伪装成 failed');
    }

    public function testExecutionDetailFromStructuredStatus(): void
    {
        $dto = ExecutionDetailDto::fromLogRow([
            'cron_id' => 1,
            'exec_batch_id' => 'batch',
            'status' => 2,
            'duration_ms' => 12,
            'message' => '执行失败？',
        ]);
        $this->assertSame('success', $dto->getStatus());
        $this->assertSame(12.0, $dto->getDurationMs());
    }

    public function testHeartbeatStatus(): void
    {
        $now = time();
        $this->assertSame('offline', CronAgentNodeRowDto::deriveHeartbeatStatus('', $now));
        $this->assertSame('online', CronAgentNodeRowDto::deriveHeartbeatStatus(date('Y-m-d H:i:s', $now - 10), $now));
        $this->assertSame('offline', CronAgentNodeRowDto::deriveHeartbeatStatus(date('Y-m-d H:i:s', $now - 46), $now));
        $this->assertSame(
            'online',
            CronAgentNodeRowDto::deriveHeartbeatStatus(date('Y-m-d H:i:s', $now - 45), $now, 15),
            '默认 15s 间隔边界 45s 仍 online',
        );
        $this->assertSame(
            'offline',
            CronAgentNodeRowDto::deriveHeartbeatStatus(date('Y-m-d H:i:s', $now - 31), $now, 10),
            '节点自己的 interval=10 → 阈值 30，31s 为 offline',
        );
    }

    public function testNodeRowMapsHeartbeatIntervalAndStatus(): void
    {
        $now = time();
        $dto = CronAgentNodeRowDto::fromEntityRow([
            'id' => 3,
            'node_name' => 'n1',
            'node_ip' => '127.0.0.1',
            'last_heartbeat_at' => date('Y-m-d H:i:s', $now - 20),
            'heartbeat_interval' => 10,
            'task_count' => 2,
        ]);
        $this->assertSame(10, $dto->getHeartbeatInterval());
        $this->assertSame(30, $dto->getStaleAfterSeconds());
        $this->assertSame('online', $dto->getStatus());
        $this->assertSame(2, $dto->getTaskCount());

        $offline = CronAgentNodeRowDto::fromEntityRow([
            'id' => 4,
            'node_name' => 'n2',
            'last_heartbeat_at' => null,
        ]);
        $this->assertSame(15, $offline->getHeartbeatInterval());
        $this->assertSame(45, $offline->getStaleAfterSeconds());
        $this->assertSame('offline', $offline->getStatus());
    }

    public function testPreviewIntervalUsesEngineParser(): void
    {
        $svc = new CronTaskManagerService();
        $ok = $svc->previewExpression(ExpressionPreviewDto::of('15'));
        $this->assertTrue($ok->isValid());
        $this->assertSame('interval', $ok->getType());
        $this->assertCount(4, $ok->getNextRuns());
        $this->assertStringContainsString('15', $ok->getDescription());
    }

    public function testPreviewInvalidExpression(): void
    {
        $svc = new CronTaskManagerService();
        $bad = $svc->previewExpression(ExpressionPreviewDto::of('???'));
        $this->assertFalse($bad->isValid());
        $this->assertSame([], $bad->getNextRuns());
    }
}

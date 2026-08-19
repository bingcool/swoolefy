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
use Test\Module\Cron\Service\CronTaskManagerService;

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
        ]);

        $this->assertSame(3, $dto->getRetry());
        $this->assertSame('interval', $dto->getExpressionType());
        $this->assertSame('******', $dto->getHttpHeaders()['Authorization'] ?? null);
        $this->assertSame('1', $dto->getHttpHeaders()['X-Demo'] ?? null);
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

    public function testLinuxCronExpressionType(): void
    {
        $this->assertSame('cron', CronTaskRowDto::deriveExpressionType('*/5 * * * *'));
        $this->assertSame('interval', CronTaskRowDto::deriveExpressionType('20'));
    }

    public function testClassifyMessage(): void
    {
        $this->assertSame('success', ExecutionDetailDto::classifyMessage('执行成功 duration=12ms'));
        $this->assertSame('failed', ExecutionDetailDto::classifyMessage('执行失败 error=1'));
        $this->assertSame('skipped', ExecutionDetailDto::classifyMessage('命中 cron_skip 跳过'));
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

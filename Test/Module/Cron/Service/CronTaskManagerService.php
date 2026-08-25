<?php

declare(strict_types=1);

namespace Test\Module\Cron\Service;

use Swoolefy\Core\Runtime\RuntimeRegistry;
use Swoolefy\Library\Db\Query;
use Swoolefy\Library\Db\Raw;
use Swoolefy\Worker\Cron\CronNodeLiveness;
use Swoolefy\Worker\Cron\ExecutionStatus;
use Swoolefy\Worker\Cron\ExpressionParser;
use Test\Module\Cron\CronAgentNodeEntity;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;
use Test\Module\Cron\CronTaskRunRequestEntity;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatResultDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentReportDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksResultDto;
use Test\Module\Cron\Dto\CronTaskManager\BatchStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\BatchStatusResultDto;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskLogRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskStatsResultDto;
use Test\Module\Cron\Dto\CronTaskManager\DashboardOverviewDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionTrendBucketDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionTrendQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\ExpressionPreviewDto;
use Test\Module\Cron\Dto\CronTaskManager\ExpressionPreviewResultDto;
use Test\Module\Cron\Dto\CronTaskManager\ListTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\NodeIdDto;
use Test\Module\Cron\Dto\CronTaskManager\RunOnceQueuedDto;
use Test\Module\Cron\Dto\CronTaskManager\RuntimeOverviewDto;
use Test\Module\Cron\Dto\CronTaskManager\SwitchTaskStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskIdDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskPayloadInputDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatusAckDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateTaskCommandDto;
use Test\Module\Cron\Exception\CronTaskException;
use Test\Module\Cron\Response\CronTaskManager\ListTasksPageResult;
use Test\Module\Cron\Response\CronTaskManager\TaskLogsPageResult;

/**
 * Cron 任务管理业务服务。
 *
 * ## 职责边界
 * - 接收/返回 DTO（或分页结果），**不依赖** HTTP Request / Response
 * - Controller 负责 Request → DTO、DTO/结果 → Response
 * - 写库前经 {@see CronTaskPayloadBuilder} 校验并规范化字段
 * - Agent 拉任务委托 {@see CronTaskService::fetchCronTask}
 *
 * ## 异常
 * 业务校验失败统一抛 {@see CronTaskException}，由上层转 API 错误码。
 */
class CronTaskManagerService
{
    /**
     * PHP 8.4 property hook：未注入时首次访问惰性创建 PayloadBuilder；set 供构造/单测注入。
     */
    private CronTaskPayloadBuilder $payloadBuilder {
        get => $this->payloadBuilder ??= new CronTaskPayloadBuilder();
        set => $this->payloadBuilder = $value;
    }

    /**
     * PHP 8.4 property hook：未注入时首次访问惰性创建 CronTaskService；set 供构造/单测注入。
     */
    private CronTaskService $cronTaskService {
        get => $this->cronTaskService ??= new CronTaskService();
        set => $this->cronTaskService = $value;
    }

    /**
     * @param CronTaskPayloadBuilder|null $payloadBuilder 非 null 时覆盖默认惰性实例（便于单测）
     * @param CronTaskService|null $cronTaskService 非 null 时覆盖默认惰性实例
     */
    public function __construct(
        ?CronTaskPayloadBuilder $payloadBuilder = null,
        ?CronTaskService $cronTaskService = null,
    ) {
        if ($payloadBuilder !== null) {
            $this->payloadBuilder = $payloadBuilder;
        }
        if ($cronTaskService !== null) {
            $this->cronTaskService = $cronTaskService;
        }
    }

    /**
     * 分页查询定时任务列表。
     *
     * 支持 keyword（name 模糊）、status、nodeId、execType 过滤；按 id 倒序。
     * 行数据映射为 {@see CronTaskRowDto} 填入 {@see ListTasksPageResult}。
     * 列表行的 nextRunAt 由引擎规则推算（不读 Worker 内存）；禁用/非法表达式为 null。
     */
    public function listTasks(ListTasksQueryDto $query): ListTasksPageResult
    {
        $keyword = trim((string)($query->getKeyword() ?? ''));
        $status = $query->getStatus();
        $nodeId = $query->getNodeId();
        $execType = $query->getExecType();

        $qb = CronTaskEntity::queryNotDeleted()->field([
            'id',
            'node_id',
            'cron_name',
            'expression',
            'command',
            'exec_type',
            'status',
            'with_block_lapping',
            'retry',
            'description',
            'cron_between',
            'cron_skip',
            'http_method',
            'http_body',
            'http_headers',
            'http_request_time_out',
            'created_at',
            'updated_at',
        ]);
        if ($keyword !== '') {
            $qb->where('cron_name', 'like', '%' . $keyword . '%');
        }
        if ($status !== null) {
            $qb->where('status', $status);
        }
        if ($nodeId !== null) {
            $qb->where('node_id', $nodeId);
        }
        if ($execType !== null) {
            $qb->where('exec_type', $execType);
        }

        $total = $qb->clone()->count();
        $list = $qb->order('id', 'desc')->limit($query->getOffset(), $query->getPageSize())->select()->toArray();

        $pageResult = new ListTasksPageResult();
        $pageResult->setTotal($total);
        $pageResult->setPage($query->getPage());
        $pageResult->setPageSize($query->getPageSize());
        foreach ($list as $row) {
            $pageResult->addListItem(CronTaskRowDto::fromEntityRow($row));
        }

        return $pageResult;
    }

    /**
     * 创建定时任务。
     *
     * 经 PayloadBuilder 做创建态必填校验后 insert；失败抛 CronTaskException。
     *
     * @return array<string, mixed> 新建任务实体属性（snake_case），供 CronTaskRowResponse 包装
     */
    public function createTask(TaskPayloadInputDto $input): array
    {
        $result = $this->payloadBuilder->build($input->toPayloadArray(), true);
        if ($result->hasError()) {
            throw CronTaskException::throw($result->getError(), -1);
        }

        $entityData = $result->getPayload()->toEntityArray();
        $name = (string)($entityData['cron_name'] ?? $entityData['name'] ?? '');
        if ($name !== '' && $this->nameExists($name, null)) {
            throw CronTaskException::throw('任务名称必须唯一', -1);
        }

        $task = new CronTaskEntity();
        $task->setData($entityData);
        $task->save();

        return $task->getAttributes();
    }

    /**
     * 更新定时任务（部分字段）。
     *
     * 要求 id>0 且任务存在；更新 payload 不能为空（isEmpty）。
     * PayloadBuilder 以 isCreate=false 只规范化提交字段。
     *
     * @return array<string, mixed> 更新后实体属性
     */
    public function updateTask(UpdateTaskCommandDto $command): array
    {
        $id = $command->getId();
        if ($id <= 0) {
            throw CronTaskException::throw('id不能为空', -1);
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            throw CronTaskException::throw('任务不存在', -1);
        }

        $result = $this->payloadBuilder->build($command->getPayload()->toPayloadArray(), false);
        if ($result->hasError()) {
            throw CronTaskException::throw($result->getError(), -1, []);
        }

        $payload = $result->getPayload();
        if ($payload === null || $payload->isEmpty()) {
            throw CronTaskException::throw('没有可更新字段', -1);
        }

        $entityData = $payload->toEntityArray();
        $newName = (string)($entityData['cron_name'] ?? $entityData['name'] ?? '');
        if ($newName !== '' && $this->nameExists($newName, $id)) {
            throw CronTaskException::throw('任务名称必须唯一', -1);
        }

        $task->setData($entityData);
        $task->save();

        return $task->getAttributes();
    }

    /**
     * 删除定时任务（软删 deleted_at）。
     *
     * 与列表同一可见性：loadById 只找 deleted_at IS NULL 的行。
     * 入参是 cron_task.id（body 或 query），不是 cron_name。
     *
     * @return int 已删除的任务 id（与入参一致，供 DeleteAck Response）
     */
    public function deleteTask(TaskIdDto $dto): int
    {
        $id = $dto->getId();
        if ($id <= 0) {
            throw CronTaskException::throw('id不能为空', -1);
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            throw CronTaskException::throw('任务不存在', -1);
        }

        $task->delete();

        return $id;
    }

    /**
     * 切换任务启用状态。
     *
     * status 仅允许 0（禁用）/ 1（启用）。
     */
    public function switchTaskStatus(SwitchTaskStatusDto $dto): TaskStatusAckDto
    {
        $id = $dto->getId();
        $status = $dto->getStatus();
        if ($id <= 0 || !in_array($status, [0, 1], true)) {
            throw CronTaskException::throw('参数错误', -1);
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            throw CronTaskException::throw('任务不存在', -1);
        }

        $task->status = $status;
        $task->save();

        return TaskStatusAckDto::of($id, $status);
    }

    /**
     * 查询全部 Cron Agent 节点（id 倒序）。
     *
     * @return list<array<string, mixed>> 节点实体行（snake_case），供 CronNodeListResponse 组装
     */
    public function listNodes(): array
    {
        $list = CronAgentNodeEntity::queryNotDeleted()->order('id', 'desc')->select()->toArray();
        if ($list === []) {
            return [];
        }
        $nodeIds = [];
        foreach ($list as $row) {
            $nodeId = (int) ($row['id'] ?? 0);
            if ($nodeId > 0) {
                $nodeIds[$nodeId] = $nodeId;
            }
        }
        $counts = [];
        if ($nodeIds !== []) {
            $rows = CronTaskEntity::queryNotDeleted()
                ->whereIn('node_id', array_values($nodeIds))
                ->field([
                    new Raw('node_id'),
                    new Raw('COUNT(*) AS total'),
                ])
                ->group('node_id')
                ->select()
                ->toArray();
            foreach ($rows as $row) {
                $counts[(int) ($row['node_id'] ?? 0)] = (int) ($row['total'] ?? 0);
            }
        }
        foreach ($list as &$row) {
            $row['task_count'] = (int) ($counts[(int)($row['id'] ?? 0)] ?? 0);
        }
        unset($row);

        return $list;
    }

    /**
     * 创建 Cron Agent 节点。
     *
     * nodeName、nodeIp 必填（非空字符串）。
     *
     * @return array<string, mixed> 新建节点实体属性
     */
    public function createNode(CreateNodeDto $dto): array
    {
        $nodeName = $dto->getNodeName();
        $nodeIp = $dto->getNodeIp();
        if ($nodeName === '' || $nodeIp === '') {
            throw CronTaskException::throw('nodeName和nodeIp不能为空', -1);
        }

        $node = new CronAgentNodeEntity();
        $node->setData([
            'node_name' => $nodeName,
            'node_ip' => $nodeIp,
            'remark' => $dto->getRemark(),
        ]);
        $node->save();

        return $node->getAttributes();
    }

    /**
     * 删除 Cron Agent 节点（软删 deleted_at）。
     *
     * 与列表同一可见性：loadById 只找 deleted_at IS NULL 的行。
     *
     * @return int 已软删的节点 id（与入参一致，供 DeleteAck Response）
     */
    public function deleteNode(NodeIdDto $dto): int
    {
        $id = $dto->getId();
        if ($id <= 0) {
            throw CronTaskException::throw('id不能为空', -1);
        }

        $node = (new CronAgentNodeEntity())->loadById($id);
        if (!$node) {
            throw CronTaskException::throw('节点不存在', -1);
        }

        $node->delete();

        return $id;
    }

    /**
     * 分页查询执行日志（可选 cron_id = taskId，id 倒序）。
     */
    public function taskLogs(TaskLogsQueryDto $query): TaskLogsPageResult
    {
        $taskId = $query->getTaskId();
        $execType = $query->getExecType();
        $triggerType = $query->getTriggerType();
        $taskName = $query->getTaskName();

        $qb = CronTaskLogEntity::queryNotDeleted();
        // 执行记录页默认只展示带 exec_batch_id 的执行行，排除配置变更审计行。
        $qb->whereNotNull('exec_batch_id')->where('exec_batch_id', '<>', '');
        if ($taskId !== null && $taskId > 0) {
            $qb->where(['cron_id' => $taskId]);
        }
        if ($execType !== null || ($taskName !== null && $taskName !== '')) {
            $taskIds = $this->queryTaskIdsByExecTypeAndName($execType, $taskName);
            if ($taskIds === []) {
                $pageResult = new TaskLogsPageResult();
                $pageResult->setTotal(0);

                return $pageResult;
            }
            if ($taskId !== null && $taskId > 0) {
                if (!isset($taskIds[$taskId])) {
                    $pageResult = new TaskLogsPageResult();
                    $pageResult->setTotal(0);

                    return $pageResult;
                }
            } else {
                $qb->whereIn('cron_id', array_values($taskIds));
            }
        }
        if ($query->getExecBatchId() !== null) {
            $qb->where('exec_batch_id', $query->getExecBatchId());
        }
        if ($triggerType !== null) {
            $qb->where('trigger_type', $triggerType);
        }
        if ($query->getStartTime() !== null) {
            $qb->where('created_at', '>=', $query->getStartTime());
        }
        if ($query->getEndTime() !== null) {
            $qb->where('created_at', '<=', $query->getEndTime());
        }
        $statusFilter = $query->getStatus();
        if ($statusFilter !== null) {
            $statusCode = ExecutionStatus::fromName($statusFilter);
            if ($statusCode !== null) {
                $qb->where('status', $statusCode);
            }
        }
        $total = $qb->clone()->count();
        $list = $qb->order('id', 'desc')->limit($query->getOffset(), $query->getPageSize())->select()->toArray();
        $taskNameMap = $this->mapTaskNamesByCronIds($this->collectCronIds($list));

        $pageResult = new TaskLogsPageResult();
        foreach ($list as $row) {
            $row['task_name'] = (string) ($taskNameMap[(int) ($row['cron_id'] ?? 0)] ?? '');
            $pageResult->addListItem(CronTaskLogRowDto::fromEntityRow($row));
        }
        $pageResult->setTotal($total);

        return $pageResult;
    }

    /**
     * 按 cron_task_log.status 做 SQL GROUP BY 统计，不读取 message / task_item。
     *
     * 时间窗为半开区间：created_at >= start AND created_at < end。
     * 成功率分母为 attempted = SUCCESS+FAILED+TIMEOUT+CANCELLED（不含 SKIPPED）。
     * 空数据返回完整零值结构。
     */
    public function taskStats(TaskStatsQueryDto $query): CronTaskStatsResultDto
    {
        $taskId = $query->getTaskId();
        if ($taskId <= 0) {
            throw CronTaskException::throw('taskId不能为空', -1);
        }

        $rows = $this->fetchStatusCounts($taskId, $query->getStart(), $query->getEnd());
        $stats = ExecutionStatus::aggregateCounts($rows);
        $duration = $this->fetchSuccessDuration($taskId, $query->getStart(), $query->getEnd());
        $stats = ExecutionStatus::withDuration(
            $stats,
            $duration['avg'],
            $duration['max'],
            $duration['samples'],
        );

        return CronTaskStatsResultDto::fromAggregated($taskId, $stats);
    }

    /**
     * Agent 拉取待执行任务。
     *
     * - execType 为 shell(1)/http(2)：只返回该类型列表（{@see AgentTasksResultDto::forExecType}）
     * - 其它/未指定：同时返回 shell + http（{@see AgentTasksResultDto::forAllTypes}）
     * 仅 status=1 且绑定 nodeId 的任务由 CronTaskService 查询并转成调度元数据。
     */
    public function agentTasks(AgentTasksQueryDto $query): AgentTasksResultDto
    {
        $nodeId = $query->getNodeId();
        $execType = (int)($query->getExecType() ?? 0);
        if ($nodeId <= 0) {
            throw CronTaskException::throw('nodeId不能为空', -1, []);
        }

        if (in_array($execType, [CronTaskPayloadDto::EXEC_TYPE_SHELL, CronTaskPayloadDto::EXEC_TYPE_HTTP], true)) {
            $list = $this->cronTaskService->fetchCronTask($execType, $nodeId);

            return AgentTasksResultDto::forExecType($nodeId, $execType, $list);
        }

        $shellTasks = $this->cronTaskService->fetchCronTask(CronTaskPayloadDto::EXEC_TYPE_SHELL, $nodeId);
        $httpTasks = $this->cronTaskService->fetchCronTask(CronTaskPayloadDto::EXEC_TYPE_HTTP, $nodeId);

        return AgentTasksResultDto::forAllTypes($nodeId, $shellTasks, $httpTasks);
    }

    /**
     * Agent 心跳：校验 nodeId 后 upsert last_heartbeat_at（缺行则插入）。
     */
    public function agentHeartbeat(AgentHeartbeatDto $dto): AgentHeartbeatResultDto
    {
        $nodeId = $dto->getNodeId();
        if ($nodeId <= 0) {
            throw CronTaskException::throw('nodeId不能为空', -1);
        }

        $this->cronTaskService->ackNodeHeartbeat((string) $nodeId);

        $result = new AgentHeartbeatResultDto();
        $result->setNodeId($nodeId);
        $result->setServerTime(date('Y-m-d H:i:s'));

        return $result;
    }

    /**
     * Agent 上报单次执行结果，写入 cron_task_log。
     *
     * taskItem 支持数组或 JSON 字符串；非数组时包一层 `['raw' => ...]`。
     *
     * @return int cronId（任务 id），供 Ack Response
     */
    public function agentReport(AgentReportDto $dto): int
    {
        $cronId = $dto->getCronId();
        $message = $dto->getMessage();
        if ($cronId <= 0 || $message === '') {
            throw CronTaskException::throw('cronId和message不能为空', -1);
        }

        $taskItem = $this->normalizeJsonField($dto->getTaskItem());

        $row = [
            'cron_id' => $cronId,
            'exec_batch_id' => $dto->getExecBatchId(),
            'task_item' => is_array($taskItem) ? $taskItem : ['raw' => (string)$taskItem],
            'message' => $message,
        ];
        if ($dto->getPid() !== null) {
            $row['pid'] = max(0, $dto->getPid());
        }
        if ($dto->getStatus() !== null) {
            $row['status'] = $dto->getStatus();
        }
        if ($dto->getTriggerType() !== null) {
            $row['trigger_type'] = $dto->getTriggerType();
        }
        if ($dto->getDurationMs() !== null) {
            $row['duration_ms'] = max(0, $dto->getDurationMs());
        }
        if ($dto->getExitCode() !== null) {
            $row['exit_code'] = $dto->getExitCode();
        }
        if ($dto->getHttpStatus() !== null) {
            $row['http_status'] = $dto->getHttpStatus();
        }
        if ($dto->getStatus() !== null) {
            $row['finished_at'] = date('Y-m-d H:i:s');
        }

        $execBatchId = trim((string)($row['exec_batch_id'] ?? ''));
        if ($execBatchId !== '') {
            $existing = CronTaskLogEntity::queryNotDeleted()
                ->where([
                    'cron_id' => $cronId,
                    'exec_batch_id' => $execBatchId,
                ])
                ->order('id', 'asc')
                ->find();
            if ($existing) {
                $id = is_array($existing) ? (int)($existing['id'] ?? 0) : (int)$existing->id;
                if ($id > 0) {
                    unset($row['cron_id'], $row['exec_batch_id']);
                    CronTaskLogEntity::query()->where(['id' => $id])->update($row);

                    return $cronId;
                }
            }
        }

        CronTaskLogEntity::query()->insert($row);

        return $cronId;
    }

    /**
     * 将 taskItem 等字段规范为数组或原值：空 → null；JSON 字符串尝试 decode。
     */
    protected function normalizeJsonField(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    /**
     * 按 status GROUP BY 计数。只查 status 列，不加载 message / task_item。
     *
     * 仅统计有 exec_batch_id 的 Execution 行（排除配置变更日志）。
     * 时间窗：created_at >= start AND created_at < end。
     *
     * @return list<array{status:int|string,total:int|string}>
     */
    protected function fetchStatusCounts(?int $cronId, ?string $start = null, ?string $end = null): array
    {
        $qb = $this->newExecutionStatsQuery($cronId, $start, $end);
        $rows = $qb->field([
            new Raw('status'),
            new Raw('COUNT(*) AS total'),
        ])->group('status')->select()->toArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * Dashboard 趋势与今日概览共用：终态 SUCCESS/FAILED/TIMEOUT/SKIPPED，按 DISTINCT exec_batch_id 计数。
     *
     * @return list<array{status:int|string,total:int|string}>
     */
    protected function fetchDistinctExecStatusCounts(?int $cronId, ?string $start = null, ?string $end = null): array
    {
        $rows = $this->newExecutionStatsQuery($cronId, $start, $end)
            ->whereIn('status', [
                ExecutionStatus::SUCCESS,
                ExecutionStatus::FAILED,
                ExecutionStatus::TIMEOUT,
                ExecutionStatus::SKIPPED,
            ])
            ->field([
                new Raw('status'),
                new Raw('COUNT(DISTINCT exec_batch_id) AS total'),
            ])
            ->group('status')
            ->select()
            ->toArray();

        return is_array($rows) ? $rows : [];
    }

    /**
     * 将 DISTINCT exec_batch_id 分组行折叠为 Dashboard executions 字段。
     * today = success+failed+timeout（与趋势 total 同源，但不含 SKIPPED）。
     *
     * @param list<array{status:int|string,total:int|string}> $rows
     * @return array{today:int,success:int,failed:int,skipped:int,timeout:int,cancelled:int}
     */
    protected function foldDashboardExecCounts(array $rows): array
    {
        $success = 0;
        $failed = 0;
        $timeout = 0;
        $skipped = 0;
        foreach ($rows as $row) {
            $status = (int) ($row['status'] ?? -1);
            $count = (int) ($row['total'] ?? 0);
            if ($status === ExecutionStatus::SUCCESS) {
                $success = $count;
            } elseif ($status === ExecutionStatus::FAILED) {
                $failed = $count;
            } elseif ($status === ExecutionStatus::TIMEOUT) {
                $timeout = $count;
            } elseif ($status === ExecutionStatus::SKIPPED) {
                $skipped = $count;
            }
        }

        return [
            'today' => $success + $failed + $timeout,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'timeout' => $timeout,
            'cancelled' => 0,
        ];
    }

    /**
     * SUCCESS 行的 AVG/MAX(duration_ms)。不从 message 解析耗时。
     *
     * @return array{avg:float,max:float,samples:int}
     */
    protected function fetchSuccessDuration(?int $cronId, ?string $start = null, ?string $end = null): array
    {
        $qb = $this->newExecutionStatsQuery($cronId, $start, $end)
            ->where('status', ExecutionStatus::SUCCESS);
        $row = $qb->field([
            new Raw('AVG(duration_ms) AS avg_duration_ms'),
            new Raw('MAX(duration_ms) AS max_duration_ms'),
            new Raw('COUNT(*) AS samples'),
        ])->find();
        $data = is_array($row) ? $row : ($row ? $row->toArray() : []);

        return [
            'avg' => round((float) ($data['avg_duration_ms'] ?? 0), 2),
            'max' => (float) ($data['max_duration_ms'] ?? 0),
            'samples' => (int) ($data['samples'] ?? 0),
        ];
    }

    /**
     * Dashboard / taskStats 共用：Execution 行（exec_batch_id 非空），不含 TEXT 列。
     */
    protected function newExecutionStatsQuery(?int $cronId, ?string $start = null, ?string $end = null): Query
    {
        $qb = CronTaskLogEntity::queryNotDeleted()
            ->whereNotNull('exec_batch_id')
            ->where('exec_batch_id', '<>', '');
        if ($cronId !== null && $cronId > 0) {
            $qb->where('cron_id', $cronId);
        }
        if ($start !== null && $start !== '') {
            $qb->where('created_at', '>=', $start);
        }
        if ($end !== null && $end !== '') {
            $qb->where('created_at', '<', $end);
        }

        return $qb;
    }

    /**
     * 任务详情：完整 Task Definition，expressionType 为展示层派生。
     *
     * @return array<string, mixed>
     */
    public function getTask(TaskIdDto $dto): array
    {
        $task = $this->requireTask($dto->getId());

        return $task->getAttributes();
    }

    /**
     * 表达式预览：统一走 ExpressionParser，计算接下来 4 次执行时间。
     */
    public function previewExpression(ExpressionPreviewDto $dto): ExpressionPreviewResultDto
    {
        $expression = $dto->getExpression();
        if ($expression === '') {
            return ExpressionPreviewResultDto::invalid('expression不能为空');
        }

        try {
            $parser = new ExpressionParser();
            $schedule = $parser->parse($expression);
            $type = $parser->isSecondInterval($expression) ? 'interval' : 'cron';
            $from = time();
            $nextRuns = [];
            $cursor = $from;
            for ($i = 0; $i < 4; $i++) {
                $cursor = $schedule->calculateNextRunAt($cursor);
                $nextRuns[] = date('Y-m-d H:i:s', $cursor);
            }
            $description = $type === 'interval'
                ? '每 ' . (int)$expression . ' 秒执行一次'
                : 'Linux Cron：' . $expression;

            return ExpressionPreviewResultDto::ok($type, $description, $nextRuns);
        } catch (\Throwable $e) {
            return ExpressionPreviewResultDto::invalid($e->getMessage());
        }
    }

    /**
     * 按 cron_id + exec_batch_id 取最近一条日志作为 Execution 详情。
     */
    public function getExecution(ExecutionDetailQueryDto $query): ExecutionDetailDto
    {
        $logId = $query->getLogId();
        $taskId = $query->getTaskId();
        $execBatchId = $query->getExecBatchId();
        if (($logId === null || $logId <= 0) && ($taskId <= 0 || $execBatchId === '')) {
            throw CronTaskException::throw('logId或(id和execBatchId)至少提供一组', -1);
        }

        $queryBuilder = CronTaskLogEntity::query();
        if ($logId !== null && $logId > 0) {
            // logId 是执行日志主键，联合传参时优先按 logId 精确命中。
            $row = $queryBuilder->where('id', $logId)->first();
        } else {
            $row = $queryBuilder->where([
                'cron_id' => $taskId,
                'exec_batch_id' => $execBatchId,
            ])->order('id', 'desc')->first();
        }
        if (!$row) {
            throw CronTaskException::throw('执行记录不存在', -1);
        }

        return ExecutionDetailDto::fromLogRow(is_array($row) ? $row : $row->toArray());
    }

    /**
     * Dashboard 聚合：任务计数 + 今日 Execution（与趋势同源 COUNT DISTINCT exec_batch_id）+ 节点心跳。
     */
    public function dashboardOverview(): DashboardOverviewDto
    {
        $total = (int)CronTaskEntity::queryNotDeleted()->count();
        $enabled = (int)CronTaskEntity::queryNotDeleted()->where('status', 1)->count();
        $todayStart = date('Y-m-d 00:00:00');
        $execCounts = $this->foldDashboardExecCounts(
            $this->fetchDistinctExecStatusCounts(null, $todayStart, null),
        );
        $nodeStats = $this->countNodeHeartbeat();

        return DashboardOverviewDto::of(
            ['total' => $total, 'enabled' => $enabled, 'disabled' => max(0, $total - $enabled)],
            $execCounts,
            $nodeStats,
        );
    }

    /**
     * 执行趋势：24h 按小时，7d/15d 按天。按 status GROUP BY 时间桶，不加载 message。
     *
     * @return list<ExecutionTrendBucketDto>
     */
    public function executionTrend(ExecutionTrendQueryDto $query): array
    {
        $range = $query->getRange();
        $hourly = $range === '24h';
        $days = $range === '15d' ? 15 : ($range === '7d' ? 7 : 1);
        $hourAnchor = strtotime(date('Y-m-d H:00:00'));
        $startTs = $hourly ? ($hourAnchor - 23 * 3600) : strtotime('-' . ($days - 1) . ' days 00:00:00');
        $start = date('Y-m-d H:i:s', $startTs);
        $fmt = $hourly ? '%Y-%m-%d %H' : '%Y-%m-%d';

        $buckets = [];
        if ($hourly) {
            for ($i = 23; $i >= 0; $i--) {
                $ts = $hourAnchor - $i * 3600;
                $key = date('Y-m-d H', $ts);
                $buckets[$key] = ExecutionTrendBucketDto::of(date('H:00', $ts), 0, 0, 0);
            }
        } else {
            for ($i = $days - 1; $i >= 0; $i--) {
                $key = date('Y-m-d', strtotime('-' . $i . ' days'));
                $buckets[$key] = ExecutionTrendBucketDto::of($key, 0, 0, 0);
            }
        }

        $rows = $this->newExecutionStatsQuery(null, $start, null)
            ->whereIn('status', [
                ExecutionStatus::SUCCESS,
                ExecutionStatus::FAILED,
                ExecutionStatus::TIMEOUT,
                ExecutionStatus::SKIPPED,
            ])
            ->field([
                new Raw("DATE_FORMAT(created_at, '{$fmt}') AS bucket"),
                new Raw('status'),
                new Raw('COUNT(DISTINCT exec_batch_id) AS total'),
            ])
            ->group('bucket,status')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $key = (string) ($row['bucket'] ?? '');
            if (!isset($buckets[$key])) {
                continue;
            }
            $status = (int) ($row['status'] ?? -1);
            $count = (int) ($row['total'] ?? 0);
            $buckets[$key] = $this->applyTrendStatusCount($buckets[$key], $status, $count);
        }

        return array_reverse(array_values($buckets));
    }

    /**
     * 将单条分组统计折叠到时间桶，只累加四类终态。
     */
    protected function applyTrendStatusCount(ExecutionTrendBucketDto $bucket, int $status, int $count): ExecutionTrendBucketDto
    {
        if ($count <= 0) {
            return $bucket;
        }
        $cur = $bucket->toDeepArray();
        $success = (int) ($cur['success'] ?? 0);
        $failed = (int) ($cur['failed'] ?? 0);
        $timeout = (int) ($cur['timeout'] ?? 0);
        $skipped = (int) ($cur['skipped'] ?? 0);
        if ($status === ExecutionStatus::SUCCESS) {
            $success += $count;
        } elseif ($status === ExecutionStatus::FAILED) {
            $failed += $count;
        } elseif ($status === ExecutionStatus::TIMEOUT) {
            $timeout += $count;
        } elseif ($status === ExecutionStatus::SKIPPED) {
            $skipped += $count;
        } else {
            return $bucket;
        }

        return ExecutionTrendBucketDto::of(
            (string) ($cur['time'] ?? ''),
            $success + $failed + $timeout + $skipped,
            $success,
            $failed,
            $timeout,
            $skipped,
        );
    }

    /**
     * Runtime 聚合。HTTP Worker 通常读不到 Cron Worker 诊断，running / lastSuccessAt 不伪造。
     */
    public function runtimeOverview(): RuntimeOverviewDto
    {
        $jobs = (int)CronTaskEntity::queryNotDeleted()->count();
        $enabled = (int)CronTaskEntity::queryNotDeleted()->where('status', 1)->count();
        $running = 0;
        $lastSuccessAt = null;
        $lastErrorAt = null;
        $processLocal = true;
        $snapshot = RuntimeRegistry::cronSnapshot();
        if (is_array($snapshot) && !empty($snapshot) && ($snapshot['enabled'] ?? true) !== false && isset($snapshot['job_count'])) {
            $processLocal = false;
            $jobs = (int)($snapshot['job_count'] ?? $jobs);
            $enabled = (int)($snapshot['enabled_count'] ?? $enabled);
            $running = (int)($snapshot['running_count'] ?? 0);
            $syncAt = $snapshot['last_config_sync'] ?? null;
            if (is_int($syncAt) && $syncAt > 0) {
                $lastSuccessAt = date('Y-m-d H:i:s', $syncAt);
            }
            $err = $snapshot['last_config_sync_error'] ?? null;
            if (is_string($err) && $err !== '') {
                $lastErrorAt = $err;
            }
        }
        $nodes = $this->countNodeHeartbeat();

        return RuntimeOverviewDto::of(
            ['jobs' => $jobs, 'enabled' => $enabled, 'running' => $running],
            ['lastSuccessAt' => $lastSuccessAt, 'lastErrorAt' => $lastErrorAt, 'processLocal' => $processLocal],
            ['online' => $nodes['online'], 'offline' => $nodes['offline']],
            $processLocal
                ? 'Cron 诊断只活在 Cron Worker；本接口聚合 DB 配置与 Agent 心跳，不伪造 running'
                : '本进程可读到 Cron Runtime 快照',
        );
    }

    /**
     * 节点详情：含心跳状态与绑定任务数。
     *
     * @return array<string, mixed>
     */
    public function getNode(NodeIdDto $dto): array
    {
        $node = $this->requireNode($dto->getId());
        $attrs = $node->getAttributes();
        $attrs['task_count'] = (int)CronTaskEntity::queryNotDeleted()->where('node_id', $dto->getId())->count();

        return $attrs;
    }

    /**
     * 部分更新节点。
     *
     * @return array<string, mixed>
     */
    public function updateNode(UpdateNodeDto $dto): array
    {
        $node = $this->requireNode($dto->getId());
        $data = [];
        if ($dto->getNodeName() !== null) {
            if ($dto->getNodeName() === '') {
                throw CronTaskException::throw('nodeName不能为空', -1);
            }
            $data['node_name'] = $dto->getNodeName();
        }
        if ($dto->getNodeIp() !== null) {
            if ($dto->getNodeIp() === '') {
                throw CronTaskException::throw('nodeIp不能为空', -1);
            }
            $data['node_ip'] = $dto->getNodeIp();
        }
        if ($dto->getRemark() !== null) {
            $data['remark'] = $dto->getRemark();
        }
        if ($data === []) {
            throw CronTaskException::throw('没有可更新字段', -1);
        }
        $node->setData($data);
        $node->save();
        $attrs = $node->getAttributes();
        $attrs['task_count'] = (int)CronTaskEntity::queryNotDeleted()->where('node_id', $dto->getId())->count();

        return $attrs;
    }

    /**
     * 批量启停：幂等赋值。
     */
    public function batchSwitchStatus(BatchStatusDto $dto): BatchStatusResultDto
    {
        $status = $dto->getStatus();
        $ids = $this->normalizePositiveIds($dto->getIds());
        if (!in_array($status, [0, 1], true) || $ids === []) {
            throw CronTaskException::throw('参数错误', -1);
        }

        $conn = (new CronTaskEntity())->getConnection();
        $conn->beginTransaction();
        try {
            $existsRows = CronTaskEntity::queryNotDeleted()
                ->whereIn('id', $ids)
                ->field(['id'])
                ->select()
                ->toArray();
            $exists = [];
            foreach ($existsRows as $row) {
                $exists[(int) ($row['id'] ?? 0)] = true;
            }
            foreach ($ids as $id) {
                if (!isset($exists[$id])) {
                    throw CronTaskException::throw("任务不存在: {$id}", -1);
                }
            }

            CronTaskEntity::queryNotDeleted()
                ->whereIn('id', $ids)
                ->update([
                    'status' => $status,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        return BatchStatusResultDto::of($ids, $status);
    }

    /**
     * 复制任务：name=原名称-copy，status=0，避免立刻进入生产调度。
     *
     * @return array<string, mixed>
     */
    public function duplicateTask(TaskIdDto $dto): array
    {
        $task = $this->requireTask($dto->getId());
        $attrs = $task->getAttributes();
        unset($attrs['id'], $attrs['created_at'], $attrs['updated_at'], $attrs['deleted_at']);
        $baseName = (string)($attrs['cron_name'] ?? $attrs['name'] ?? 'task');
        $attrs['cron_name'] = $this->uniqueCopyName($baseName);
        unset($attrs['name']);
        $attrs['status'] = 0;
        // 副本必须是未删除行，否则列表 select() 能看见、loadById 因 SoftDelete 找不到
        $attrs['deleted_at'] = null;
        $copy = new CronTaskEntity();
        $copy->setData($attrs);
        $copy->save();

        return $copy->getAttributes();
    }

    /**
     * 入队手动执行。不改 cron_task，不声称已执行。
     */
    public function enqueueRunOnce(TaskIdDto $dto): RunOnceQueuedDto
    {
        $this->requireTask($dto->getId());
        $requestedAt = date('Y-m-d H:i:s');
        CronTaskRunRequestEntity::query()->insert([
            'cron_id' => $dto->getId(),
            'requested_at' => $requestedAt,
            'consumed_at' => null,
        ]);

        return RunOnceQueuedDto::of($dto->getId(), $requestedAt);
    }

    /**
     * 消费单条手动执行请求（Cron Worker Polling 回调）。
     *
     * 一条 request 对应一次 Execution，按主键 id ack，不得按 cron_id 清空全部 pending。
     */
    public function ackRunOnce(int $requestId): void
    {
        if ($requestId <= 0) {
            return;
        }
        CronTaskRunRequestEntity::query()
            ->where('id', $requestId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * 取出某任务当前最老的一条未消费手动执行请求 ID（FIFO）。无则 null。
     */
    public function findOnePendingRunOnce(int $cronTaskId): ?int
    {
        if ($cronTaskId <= 0) {
            return null;
        }
        $row = CronTaskRunRequestEntity::query()
            ->where('cron_id', $cronTaskId)
            ->whereNull('consumed_at')
            ->order('id', 'asc')
            ->first();
        if (!$row) {
            return null;
        }
        $attrs = is_array($row) ? $row : $row->toArray();
        $id = (int) ($attrs['id'] ?? 0);

        return $id > 0 ? $id : null;
    }

    /**
     * 列出某任务全部未消费的手动执行请求 ID（FIFO）。
     *
     * @return list<int>
     */
    public function listPendingRunOnceIds(int $cronTaskId): array
    {
        if ($cronTaskId <= 0) {
            return [];
        }
        $rows = CronTaskRunRequestEntity::query()
            ->where('cron_id', $cronTaskId)
            ->whereNull('consumed_at')
            ->order('id', 'asc')
            ->field(['id'])
            ->select()
            ->toArray();
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * 批量列出多任务未消费的手动执行请求 ID（FIFO，按 request id 升序）。
     *
     * @param list<int> $cronTaskIds
     * @return array<int, list<int>> key=cron_id，value=request_id 列表
     */
    public function listPendingRunOnceIdsByCronTaskIds(array $cronTaskIds): array
    {
        $normalized = [];
        foreach ($cronTaskIds as $cronTaskId) {
            $id = (int) $cronTaskId;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        if ($normalized === []) {
            return [];
        }
        $ids = array_values($normalized);
        $rows = CronTaskRunRequestEntity::query()
            ->whereIn('cron_id', $ids)
            ->whereNull('consumed_at')
            ->order('id', 'asc')
            ->field(['id', 'cron_id'])
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($rows as $row) {
            $cronId = (int) ($row['cron_id'] ?? 0);
            $requestId = (int) ($row['id'] ?? 0);
            if ($cronId <= 0 || $requestId <= 0) {
                continue;
            }
            if (!isset($grouped[$cronId])) {
                $grouped[$cronId] = [];
            }
            $grouped[$cronId][] = $requestId;
        }

        return $grouped;
    }

    /**
     * 是否有未消费的手动执行请求。
     */
    public function hasPendingRunOnce(int $cronTaskId): bool
    {
        return $this->findOnePendingRunOnce($cronTaskId) !== null;
    }

    /**
     * @return array{total:int,online:int,offline:int}
     */
    protected function countNodeHeartbeat(): array
    {
        $nodes = CronAgentNodeEntity::queryNotDeleted()
            ->field(['last_heartbeat_at', 'heartbeat_interval'])
            ->select()
            ->toArray();
        $now = time();
        $online = 0;
        $offline = 0;
        foreach ($nodes as $node) {
            $interval = CronNodeLiveness::normalizeInterval((int)($node['heartbeat_interval'] ?? 0));
            $status = CronNodeLiveness::status(
                $now,
                CronNodeLiveness::parseHeartbeatAt($node['last_heartbeat_at'] ?? null),
                $interval,
            );
            if ($status === CronNodeLiveness::STATUS_ONLINE) {
                $online++;
            } else {
                $offline++;
            }
        }

        return [
            'total' => count($nodes),
            'online' => $online,
            'offline' => $offline,
        ];
    }

    protected function requireTask(int $id): CronTaskEntity
    {
        if ($id <= 0) {
            throw CronTaskException::throw('id不能为空', -1);
        }
        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            throw CronTaskException::throw('任务不存在', -1);
        }

        return $task;
    }

    protected function requireNode(int $id): CronAgentNodeEntity
    {
        if ($id <= 0) {
            throw CronTaskException::throw('id不能为空', -1);
        }
        $node = (new CronAgentNodeEntity())->loadById($id);
        if (!$node) {
            throw CronTaskException::throw('节点不存在', -1);
        }

        return $node;
    }

    /**
     * 名称唯一性含软删行：表上 uniq_cron_name 不区分 deleted_at。
     */
    protected function nameExists(string $name, ?int $exceptId): bool
    {
        $qb = CronTaskEntity::query()->where('cron_name', $name);
        if ($exceptId !== null) {
            $qb->where('id', '<>', $exceptId);
        }

        return $qb->count() > 0;
    }

    protected function uniqueCopyName(string $baseName): string
    {
        return self::allocateCopyName($baseName, fn(string $n) => $this->nameExists($n, null));
    }

    /**
     * 复制命名：原名称-copy，冲突则 -copy-2、-copy-3…
     *
     * @param callable(string):bool $nameExists
     */
    public static function allocateCopyName(string $baseName, callable $nameExists): string
    {
        $candidate = $baseName . '-copy';
        $i = 2;
        while ($nameExists($candidate)) {
            $candidate = $baseName . '-copy-' . $i;
            $i++;
        }

        return $candidate;
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    private function normalizePositiveIds(array $ids): array
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value <= 0 || isset($normalized[$value])) {
                continue;
            }
            $normalized[$value] = true;
        }

        return array_map('intval', array_keys($normalized));
    }

    /**
     * @return array<int, int> key/value 都是 cron_task.id，便于 whereIn 与快速判断。
     */
    private function queryTaskIdsByExecTypeAndName(?int $execType, ?string $taskName): array
    {
        $qb = CronTaskEntity::queryNotDeleted()->field(['id']);
        if ($execType !== null) {
            $qb->where('exec_type', $execType);
        }
        if ($taskName !== null && $taskName !== '') {
            $qb->where('cron_name', 'like', '%' . $taskName . '%');
        }
        $rows = $qb->select()->toArray();
        $taskIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $taskIds[$id] = $id;
            }
        }

        return $taskIds;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function collectCronIds(array $rows): array
    {
        $cronIds = [];
        foreach ($rows as $row) {
            $cronId = (int) ($row['cron_id'] ?? 0);
            if ($cronId > 0) {
                $cronIds[$cronId] = $cronId;
            }
        }

        return array_values($cronIds);
    }

    /**
     * @param list<int> $cronIds
     * @return array<int, string> key=cron_id value=cron_name
     */
    private function mapTaskNamesByCronIds(array $cronIds): array
    {
        if ($cronIds === []) {
            return [];
        }
        $rows = CronTaskEntity::queryNotDeleted()
            ->whereIn('id', $cronIds)
            ->field(['id', 'cron_name'])
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (string) ($row['cron_name'] ?? '');
        }

        return $map;
    }
}

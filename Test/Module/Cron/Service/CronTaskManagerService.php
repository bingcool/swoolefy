<?php

declare(strict_types=1);

namespace Test\Module\Cron\Service;

use Test\Module\Cron\CronAgentNodeEntity;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatResultDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentReportDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksResultDto;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskLogRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskPayloadDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskRowDto;
use Test\Module\Cron\Dto\CronTaskManager\CronTaskStatsResultDto;
use Test\Module\Cron\Dto\CronTaskManager\ListTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\NodeIdDto;
use Test\Module\Cron\Dto\CronTaskManager\SwitchTaskStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskIdDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskPayloadInputDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatusAckDto;
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
     */
    public function listTasks(ListTasksQueryDto $query): ListTasksPageResult
    {
        $keyword = trim((string)($query->getKeyword() ?? ''));
        $status = $query->getStatus();
        $nodeId = $query->getNodeId();
        $execType = $query->getExecType();

        $qb = CronTaskEntity::query()->field([
            'id',
            'node_id',
            'name',
            'expression',
            'command',
            'exec_type',
            'status',
            'with_block_lapping',
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
            $qb->where('name', 'like', '%' . $keyword . '%');
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

        $task = new CronTaskEntity();
        $task->setData($result->getPayload()->toEntityArray());
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

        $task->setData($payload->toEntityArray());
        $task->save();

        return $task->getAttributes();
    }

    /**
     * 删除定时任务。
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
        return CronAgentNodeEntity::query()->order('id', 'desc')->select()->toArray();
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
     * 删除 Cron Agent 节点。
     *
     * @return int 已删除的节点 id
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
     * 分页查询某任务的执行日志（cron_id = taskId，id 倒序）。
     */
    public function taskLogs(TaskLogsQueryDto $query): TaskLogsPageResult
    {
        $taskId = $query->getTaskId();
        if ($taskId <= 0) {
            throw CronTaskException::throw('taskId不能为空', -1);
        }

        $qb = CronTaskLogEntity::query()->where([
            'cron_id' => $taskId,
        ]);
        $total = $qb->clone()->count();
        $list = $qb->order('id', 'desc')->limit($query->getOffset(), $query->getPageSize())->select()->toArray();

        $pageResult = new TaskLogsPageResult();
        $pageResult->setTotal($total);
        foreach ($list as $row) {
            $pageResult->addListItem(CronTaskLogRowDto::fromEntityRow($row));
        }

        return $pageResult;
    }

    /**
     * 根据最近最多 2000 条日志文本粗算执行统计。
     *
     * 成功/失败/跳过靠 message 关键词匹配（中英）；耗时用
     * 「耗时|duration|cost = N ms|s」正则提取后求平均。
     * 非严谨状态机统计，仅供运营看板参考。
     */
    public function taskStats(TaskStatsQueryDto $query): CronTaskStatsResultDto
    {
        $taskId = $query->getTaskId();
        if ($taskId <= 0) {
            throw CronTaskException::throw('taskId不能为空', -1);
        }

        $logs = CronTaskLogEntity::query()->field(['id', 'message', 'created_at'])->where([
            'cron_id' => $taskId,
        ])->order('id', 'desc')->limit(0, 2000)->select()->toArray();

        $total = count($logs);
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $durationTotalMs = 0.0;
        $durationSamples = 0;

        foreach ($logs as $log) {
            $message = (string)($log['message'] ?? '');
            $normalized = strtolower($message);

            if (strpos($message, '成功') !== false || strpos($normalized, 'success') !== false) {
                $success++;
            }
            if (strpos($message, '失败') !== false || strpos($message, '报错') !== false || strpos($normalized, 'error') !== false || strpos($normalized, 'fail') !== false) {
                $failed++;
            }
            if (strpos($message, '跳过') !== false || strpos($message, '不能执行') !== false || strpos($normalized, 'skip') !== false) {
                $skipped++;
            }

            $durationMs = $this->extractDurationMs($message);
            if ($durationMs > 0) {
                $durationTotalMs += $durationMs;
                $durationSamples++;
            }
        }

        $result = new CronTaskStatsResultDto();
        $result->setTaskId($taskId);
        $result->setTotal($total);
        $result->setSuccess($success);
        $result->setFailed($failed);
        $result->setSkipped($skipped);
        $result->setSuccessRate($total > 0 ? round(($success / $total) * 100, 2) : 0.0);
        $result->setAvgDurationMs($durationSamples > 0 ? round($durationTotalMs / $durationSamples, 2) : 0.0);
        $result->setSamples($durationSamples);

        return $result;
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
     * Agent 心跳：校验 nodeId 后返回服务端当前时间（暂不写库）。
     */
    public function agentHeartbeat(AgentHeartbeatDto $dto): AgentHeartbeatResultDto
    {
        $nodeId = $dto->getNodeId();
        if ($nodeId <= 0) {
            throw CronTaskException::throw('nodeId不能为空', -1);
        }

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

        CronTaskLogEntity::query()->insert([
            'cron_id' => $cronId,
            'exec_batch_id' => $dto->getExecBatchId(),
            'pid' => $dto->getPid(),
            'task_item' => is_array($taskItem) ? $taskItem : ['raw' => (string)$taskItem],
            'message' => $message,
        ]);

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
     * 从日志 message 提取耗时（毫秒）。
     *
     * 匹配 `耗时|duration|cost` + `=`/`:` + 数字，可选单位 ms（默认）或 s（×1000）。
     * 未匹配返回 0.0（不计入平均）。
     */
    protected function extractDurationMs(string $message): float
    {
        if (preg_match('/(?:耗时|duration|cost)\\s*[:=]\\s*(\\d+(?:\\.\\d+)?)\\s*(ms|s)?/i', $message, $match)) {
            $value = (float)$match[1];
            $unit = strtolower((string)($match[2] ?? 'ms'));
            if ($unit === 's') {
                return $value * 1000;
            }

            return $value;
        }

        return 0.0;
    }
}

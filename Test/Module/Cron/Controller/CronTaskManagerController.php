<?php
namespace Test\Module\Cron\Controller;

use Swoolefy\Core\Controller\BController;
use Test\Module\Cron\CronAgentNodeEntity;
use Test\Module\Cron\CronTaskEntity;
use Test\Module\Cron\CronTaskLogEntity;
use Test\Module\Cron\Request\CronAgentHeartbeatRequest;
use Test\Module\Cron\Request\CronAgentReportRequest;
use Test\Module\Cron\Request\CronAgentTasksQueryRequest;
use Test\Module\Cron\Request\CronNodeCreateRequest;
use Test\Module\Cron\Request\CronNodeIdRequest;
use Test\Module\Cron\Request\CronTaskCreateRequest;
use Test\Module\Cron\Request\CronTaskIdRequest;
use Test\Module\Cron\Request\CronTaskListQueryRequest;
use Test\Module\Cron\Request\CronTaskLogsQueryRequest;
use Test\Module\Cron\Request\CronTaskStatsQueryRequest;
use Test\Module\Cron\Request\CronTaskStatusSwitchRequest;
use Test\Module\Cron\Request\CronTaskUpdateRequest;
use Test\Module\Cron\Response\CronAgentHeartbeatResponse;
use Test\Module\Cron\Response\CronAgentReportAckResponse;
use Test\Module\Cron\Response\CronAgentTasksResponse;
use Test\Module\Cron\Response\CronDeleteAckResponse;
use Test\Module\Cron\Response\CronNodeListResponse;
use Test\Module\Cron\Response\CronNodeRowResponse;
use Test\Module\Cron\Response\CronPagedListResponse;
use Test\Module\Cron\Response\CronTaskRowResponse;
use Test\Module\Cron\Response\CronTaskStatsResponse;
use Test\Module\Cron\Response\CronTaskStatusAckResponse;
use Test\Module\Cron\Service\CronTaskService;

class CronTaskManagerController extends BController
{
    const TASK_EXEC_TYPE_SHELL = 1;
    const TASK_EXEC_TYPE_HTTP = 2;

    public function listTasks(CronTaskListQueryRequest $request): CronPagedListResponse
    {
        $page = $request->getPage();
        $pageSize = $request->getPageSize();
        $offset = ($page - 1) * $pageSize;
        $keyword = $request->getKeyword();
        $status = $request->getStatus();
        $nodeId = $request->getNodeId();
        $execType = $request->getExecType();

        $query = CronTaskEntity::query()->field([
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
            $query->where('name', 'like', '%' . $keyword . '%');
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }
        if ($nodeId !== null && $nodeId !== '') {
            $query->where('node_id', (int)$nodeId);
        }
        if ($execType !== null && $execType !== '') {
            $query->where('exec_type', (int)$execType);
        }

        $total = $query->clone()->count();
        $list = $query->order('id', 'desc')->limit($offset, $pageSize)->select()->toArray();

        return new CronPagedListResponse($page, $pageSize, $total, $list);
    }

    public function createTask(CronTaskCreateRequest $request): ?CronTaskRowResponse
    {
        list($data, $error) = $this->buildTaskPayload($request->toPayloadArray(), true);
        if ($error !== null) {
            $this->returnJson([], -1, $error);
            return null;
        }

        $task = new CronTaskEntity();
        $task->setData($data);
        $task->save();

        return new CronTaskRowResponse($task->getAttributes());
    }

    public function updateTask(CronTaskUpdateRequest $request): ?CronTaskRowResponse
    {
        $id = $request->getId();
        if ($id <= 0) {
            $this->returnJson([], -1, 'id不能为空');
            return null;
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            $this->returnJson([], -1, '任务不存在');
            return null;
        }

        list($data, $error) = $this->buildTaskPayload($request->toPayloadArray(), false);
        if ($error !== null) {
            $this->returnJson([], -1, $error);
            return null;
        }

        if (empty($data)) {
            $this->returnJson([], -1, '没有可更新字段');
            return null;
        }

        $task->setData($data);
        $task->save();

        return new CronTaskRowResponse($task->getAttributes());
    }

    public function deleteTask(CronTaskIdRequest $request): ?CronDeleteAckResponse
    {
        $id = $request->getId();
        if ($id <= 0) {
            $this->returnJson([], -1, 'id不能为空');
            return null;
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            $this->returnJson([], -1, '任务不存在');
            return null;
        }

        $task->delete();

        return new CronDeleteAckResponse($id);
    }

    public function switchTaskStatus(CronTaskStatusSwitchRequest $request): ?CronTaskStatusAckResponse
    {
        $id = $request->getId();
        $status = $request->getStatus();
        if ($id <= 0 || !in_array($status, [0, 1], true)) {
            $this->returnJson([], -1, '参数错误');
            return null;
        }

        $task = (new CronTaskEntity())->loadById($id);
        if (!$task) {
            $this->returnJson([], -1, '任务不存在');
            return null;
        }

        $task->status = $status;
        $task->save();

        return new CronTaskStatusAckResponse($id, $status);
    }

    public function listNodes(): CronNodeListResponse
    {
        $list = CronAgentNodeEntity::query()->order('id', 'desc')->select()->toArray();

        return new CronNodeListResponse($list);
    }

    public function createNode(CronNodeCreateRequest $request): ?CronNodeRowResponse
    {
        $nodeName = $request->getNodeName();
        $nodeIp = $request->getNodeIp();
        $remark = $request->getRemark();
        if ($nodeName === '' || $nodeIp === '') {
            $this->returnJson([], -1, 'node_name和node_ip不能为空');
            return null;
        }

        $node = new CronAgentNodeEntity();
        $node->setData([
            'node_name' => $nodeName,
            'node_ip' => $nodeIp,
            'remark' => $remark,
        ]);
        $node->save();

        return new CronNodeRowResponse($node->getAttributes());
    }

    public function deleteNode(CronNodeIdRequest $request): ?CronDeleteAckResponse
    {
        $id = $request->getId();
        if ($id <= 0) {
            $this->returnJson([], -1, 'id不能为空');
            return null;
        }

        $node = (new CronAgentNodeEntity())->loadById($id);
        if (!$node) {
            $this->returnJson([], -1, '节点不存在');
            return null;
        }

        $node->delete();

        return new CronDeleteAckResponse($id);
    }

    public function taskLogs(CronTaskLogsQueryRequest $request): ?CronPagedListResponse
    {
        $taskId = $request->getTaskId();
        $page = $request->getPage();
        $pageSize = $request->getPageSize();
        $offset = ($page - 1) * $pageSize;
        if ($taskId <= 0) {
            $this->returnJson([], -1, 'task_id不能为空');
            return null;
        }

        $query = CronTaskLogEntity::query()->where([
            'cron_id' => $taskId,
        ]);
        $total = $query->clone()->count();
        $list = $query->order('id', 'desc')->limit($offset, $pageSize)->select()->toArray();

        return new CronPagedListResponse($page, $pageSize, $total, $list);
    }

    public function taskStats(CronTaskStatsQueryRequest $request): ?CronTaskStatsResponse
    {
        $taskId = $request->getTaskId();
        if ($taskId <= 0) {
            $this->returnJson([], -1, 'task_id不能为空');
            return null;
        }

        $logs = CronTaskLogEntity::query()->field(['id', 'message', 'created_at'])->where([
            'cron_id' => $taskId,
        ])->order('id', 'desc')->limit(0, 2000)->select()->toArray();

        $total = count($logs);
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $durationTotalMs = 0;
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

        $successRate = $total > 0 ? round(($success / $total) * 100, 2) : 0.0;
        $avgDurationMs = $durationSamples > 0 ? round($durationTotalMs / $durationSamples, 2) : 0.0;

        return new CronTaskStatsResponse(
            $taskId,
            $total,
            $success,
            $failed,
            $skipped,
            $successRate,
            $avgDurationMs,
            $durationSamples
        );
    }

    public function agentTasks(CronAgentTasksQueryRequest $request): ?CronAgentTasksResponse
    {
        $nodeId = $request->getNodeId();
        $execType = (int)($request->getExecType() ?? 0);
        if ($nodeId <= 0) {
            $this->returnJson([], -1, 'node_id不能为空');
            return null;
        }

        $service = new CronTaskService();
        if (in_array($execType, [self::TASK_EXEC_TYPE_SHELL, self::TASK_EXEC_TYPE_HTTP], true)) {
            $list = $service->fetchCronTask($execType, $nodeId);

            return CronAgentTasksResponse::forExecType($nodeId, $execType, $list);
        }

        $shellTasks = $service->fetchCronTask(self::TASK_EXEC_TYPE_SHELL, $nodeId);
        $httpTasks = $service->fetchCronTask(self::TASK_EXEC_TYPE_HTTP, $nodeId);

        return CronAgentTasksResponse::forAllTypes($nodeId, $shellTasks, $httpTasks);
    }

    public function agentHeartbeat(CronAgentHeartbeatRequest $request): ?CronAgentHeartbeatResponse
    {
        $nodeId = $request->getNodeId();
        if ($nodeId <= 0) {
            $this->returnJson([], -1, 'node_id不能为空');
            return null;
        }

        return new CronAgentHeartbeatResponse($nodeId, date('Y-m-d H:i:s'));
    }

    public function agentReport(CronAgentReportRequest $request): ?CronAgentReportAckResponse
    {
        $cronId = $request->getCronId();
        $message = $request->getMessage();
        if ($cronId <= 0 || $message === '') {
            $this->returnJson([], -1, 'cron_id和message不能为空');
            return null;
        }

        $taskItem = $this->normalizeJsonField($request->getTaskItem());
        $execBatchId = $request->getExecBatchId();
        $pid = $request->getPid();

        CronTaskLogEntity::query()->insert([
            'cron_id' => $cronId,
            'exec_batch_id' => $execBatchId,
            'pid' => $pid,
            'task_item' => is_array($taskItem) ? $taskItem : ['raw' => (string)$taskItem],
            'message' => $message
        ]);

        return new CronAgentReportAckResponse($cronId);
    }

    protected function buildTaskPayload(array $payload, bool $isCreate): array
    {
        $name = trim((string)($payload['name'] ?? ''));
        $expression = trim((string)($payload['expression'] ?? ''));
        $command = trim((string)($payload['command'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $nodeId = isset($payload['node_id']) ? (int)$payload['node_id'] : null;
        $execType = isset($payload['exec_type']) ? (int)$payload['exec_type'] : null;
        $status = isset($payload['status']) ? (int)$payload['status'] : null;
        $withBlockLapping = isset($payload['with_block_lapping']) ? (int)$payload['with_block_lapping'] : null;
        $httpMethod = strtoupper(trim((string)($payload['http_method'] ?? 'GET')));
        $httpTimeout = isset($payload['http_request_time_out']) ? (int)$payload['http_request_time_out'] : null;
        $cronBetween = $this->normalizeTimeRanges($payload['cron_between'] ?? null);
        $cronSkip = $this->normalizeTimeRanges($payload['cron_skip'] ?? null);
        $httpBody = $this->normalizeJsonField($payload['http_body'] ?? null);
        $httpHeaders = $this->normalizeJsonField($payload['http_headers'] ?? null);

        if ($isCreate) {
            if ($name === '' || $expression === '' || $command === '') {
                return [[], 'name/expression/command为必填'];
            }
            if (!in_array($execType, [self::TASK_EXEC_TYPE_SHELL, self::TASK_EXEC_TYPE_HTTP], true)) {
                return [[], 'exec_type仅支持1(shell)和2(http)'];
            }
            if ($nodeId <= 0) {
                return [[], 'node_id为必填'];
            }
        }

        $data = [];
        if ($name !== '') {
            $data['name'] = $name;
        }
        if ($expression !== '') {
            $data['expression'] = $expression;
        }
        if ($command !== '') {
            $data['command'] = $command;
        }
        if ($description !== '' || $isCreate) {
            $data['description'] = $description;
        }
        if ($nodeId !== null && $nodeId > 0) {
            $data['node_id'] = $nodeId;
        }
        if ($execType !== null && in_array($execType, [self::TASK_EXEC_TYPE_SHELL, self::TASK_EXEC_TYPE_HTTP], true)) {
            $data['exec_type'] = $execType;
        }
        if ($status !== null && in_array($status, [0, 1], true)) {
            $data['status'] = $status;
        }
        if ($withBlockLapping !== null && in_array($withBlockLapping, [0, 1], true)) {
            $data['with_block_lapping'] = $withBlockLapping;
        }

        if ($httpMethod !== '' || $isCreate) {
            $data['http_method'] = $httpMethod === '' ? 'GET' : $httpMethod;
        }
        if ($httpTimeout !== null && $httpTimeout >= 0) {
            $data['http_request_time_out'] = $httpTimeout;
        } elseif ($isCreate) {
            $data['http_request_time_out'] = 30;
        }

        if ($cronBetween !== null || $isCreate) {
            $data['cron_between'] = $cronBetween;
        }
        if ($cronSkip !== null || $isCreate) {
            $data['cron_skip'] = $cronSkip;
        }
        if ($httpBody !== null || $isCreate) {
            $data['http_body'] = $httpBody;
        }
        if ($httpHeaders !== null || $isCreate) {
            $data['http_headers'] = $httpHeaders;
        }

        return [$data, null];
    }

    protected function normalizeJsonField($value)
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

    protected function normalizeTimeRanges($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            return null;
        }

        $ranges = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $start = trim((string)($item['start'] ?? ''));
            $end = trim((string)($item['end'] ?? ''));
            if ($start === '' || $end === '') {
                continue;
            }
            $ranges[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return !empty($ranges) ? $ranges : null;
    }

    protected function extractDurationMs(string $message)
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

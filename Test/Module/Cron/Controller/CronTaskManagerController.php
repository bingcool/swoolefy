<?php

namespace Test\Module\Cron\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentReportDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\BatchStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionDetailQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\ExecutionTrendQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\ExpressionPreviewDto;
use Test\Module\Cron\Dto\CronTaskManager\ListTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\NodeIdDto;
use Test\Module\Cron\Dto\CronTaskManager\SwitchTaskStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskIdDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskPayloadInputDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateTaskCommandDto;
use Test\Module\Cron\Request\CronTaskManager\BatchStatusRequest;
use Test\Module\Cron\Request\CronTaskManager\CronAgentHeartbeatRequest;
use Test\Module\Cron\Request\CronTaskManager\CronAgentReportRequest;
use Test\Module\Cron\Request\CronTaskManager\CronAgentTasksQueryRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeIdRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeUpdateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskIdRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskStatsQueryRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskStatusSwitchRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest;
use Test\Module\Cron\Request\CronTaskManager\DashboardTrendRequest;
use Test\Module\Cron\Request\CronTaskManager\ExecutionDetailRequest;
use Test\Module\Cron\Request\CronTaskManager\ExpressionPreviewRequest;
use Test\Module\Cron\Request\CronTaskManager\ListTasksRequest;
use Test\Module\Cron\Request\CronTaskManager\TaskLogsQueryRequest;
use Test\Module\Cron\Response\CronTaskManager\BatchStatusResponse;
use Test\Module\Cron\Response\CronTaskManager\CronAgentHeartbeatResponse;
use Test\Module\Cron\Response\CronTaskManager\CronAgentReportAckResponse;
use Test\Module\Cron\Response\CronTaskManager\CronAgentTasksResponse;
use Test\Module\Cron\Response\CronTaskManager\CronDeleteAckResponse;
use Test\Module\Cron\Response\CronTaskManager\CronNodeListResponse;
use Test\Module\Cron\Response\CronTaskManager\CronNodeRowResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskRowResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskStatsResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskStatusAckResponse;
use Test\Module\Cron\Response\CronTaskManager\DashboardOverviewResponse;
use Test\Module\Cron\Response\CronTaskManager\ExecutionDetailResponse;
use Test\Module\Cron\Response\CronTaskManager\ExecutionTrendResponse;
use Test\Module\Cron\Response\CronTaskManager\ExpressionPreviewResponse;
use Test\Module\Cron\Response\CronTaskManager\ListTasksResponse;
use Test\Module\Cron\Response\CronTaskManager\RunOnceQueuedResponse;
use Test\Module\Cron\Response\CronTaskManager\RuntimeOverviewResponse;
use Test\Module\Cron\Response\CronTaskManager\TaskLogsResponse;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * Cron 任务管理控制器 —— 仅做 Request ↔ DTO / Response 映射，业务在 {@see CronTaskManagerService}。
 *
 * 路由定义：`Test/Router/Module/CronManager.php`（前缀 `api/v1`，默认端口 9501）。
 * 各方法 PHPDoc 中 curl 代码块无行首 `*`，便于直接复制执行。
 */
class CronTaskManagerController extends BController
{
    /** PHP 8.4 property hook：首次访问时惰性创建 Service */
    private CronTaskManagerService $cronTaskManagerService {
        get => $this->cronTaskManagerService ??= new CronTaskManagerService();
    }

    /**
     * 分页查询定时任务列表。
     *
     * Route: GET /api/v1/tasks
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks?page=1&pageSize=20&keyword=&status=1&nodeId=1&execType=1' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(
        "分页查询定时任务列表"
    )]
    public function listTasks(ListTasksRequest $request): ListTasksResponse
    {
        $query = (new ListTasksQueryDto())
            ->setPage($request->getPage())
            ->setPageSize($request->getPageSize())
            ->setKeyword($request->getKeyword())
            ->setStatus($request->getStatus())
            ->setNodeId($request->getNodeId())
            ->setExecType($request->getExecType());

        return new ListTasksResponse($this->cronTaskManagerService->listTasks($query));
    }

    /**
     * 创建定时任务。
     *
     * Route: POST /api/v1/tasks
     *
     ```bash
     # shell 任务示例
     curl -X POST 'http://127.0.0.1:9501/api/v1/tasks' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "name": "demo-shell",
         "expression": "5 * * * *",
         "command": "php script.php start --c=Demo",
         "execType": 1,
         "nodeId": 1,
         "description": "demo",
         "status": 1,
         "withBlockLapping": 0
       }'

     # http 任务示例
     curl -X POST 'http://127.0.0.1:9501/api/v1/tasks' \
       -H 'Content-Type: application/json' \
       -d '{
         "name": "demo-http",
         "expression": "0 * * * *",
         "command": "https://httpbin.org/post",
         "execType": 2,
         "nodeId": 1,
         "httpMethod": "POST",
         "httpRequestTimeOut": 30,
         "httpBody": {"ping": true},
         "httpHeaders": {"X-Demo": "1"}
       }'
     ```
     */
    #[ApiOperation(
        "创建定时任务"
    )]
    public function createTask(CronTaskCreateRequest $request): CronTaskRowResponse
    {
        $input = TaskPayloadInputDto::fromPayloadArray($request->toPayloadArray());

        return new CronTaskRowResponse($this->cronTaskManagerService->createTask($input));
    }

    /**
     * 更新定时任务（部分字段）。
     *
     * Route: PUT /api/v1/tasks
     *
     ```bash
     curl -X PUT 'http://127.0.0.1:9501/api/v1/tasks' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "id": 1,
         "name": "demo-shell-updated",
         "expression": "10 * * * *",
         "status": 1
       }'
     ```
     */
    #[ApiOperation(
        "更新定时任务"
    )]
    public function updateTask(CronTaskUpdateRequest $request): CronTaskRowResponse
    {
        $command = (new UpdateTaskCommandDto())
            ->setId($request->getId())
            ->setPayload(TaskPayloadInputDto::fromPayloadArray($request->toPayloadArray()));

        return new CronTaskRowResponse($this->cronTaskManagerService->updateTask($command));
    }

    /**
     * 删除定时任务。
     *
     * Route: DELETE /api/v1/tasks
     *
     ```bash
     curl -X DELETE 'http://127.0.0.1:9501/api/v1/tasks?id=1' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{"id": 1}'
     ```
     */
    #[ApiOperation(
        "删除定时任务"
    )]
    public function deleteTask(CronTaskIdRequest $request): CronDeleteAckResponse
    {
        $id = $this->cronTaskManagerService->deleteTask(TaskIdDto::of($request->getId()));

        return new CronDeleteAckResponse($id);
    }

    /**
     * 切换定时任务启用状态。
     *
     * Route: POST|PUT /api/v1/tasks/status
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/tasks/status' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{"id": 1, "status": 0}'

     curl -X PUT 'http://127.0.0.1:9501/api/v1/tasks/status' \
       -H 'Content-Type: application/json' \
       -d '{"id": 1, "status": 1}'
     ```
     */
    #[ApiOperation(
        "切换定时任务启用状态"
    )]
    public function switchTaskStatus(CronTaskStatusSwitchRequest $request): CronTaskStatusAckResponse
    {
        $dto = (new SwitchTaskStatusDto())
            ->setId($request->getId())
            ->setStatus($request->getStatus());
        $ack = $this->cronTaskManagerService->switchTaskStatus($dto);

        return new CronTaskStatusAckResponse($ack->getId(), $ack->getStatus());
    }

    /**
     * 查询 Cron Agent 节点列表。
     *
     * Route: GET /api/v1/nodes
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/nodes' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(
        "查询 Cron Agent 节点列表"
    )]
    public function listNodes(): CronNodeListResponse
    {
        return new CronNodeListResponse($this->cronTaskManagerService->listNodes());
    }

    /**
     * 创建 Cron Agent 节点。
     *
     * Route: POST /api/v1/nodes
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/nodes' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "nodeName": "agent-1",
         "nodeIp": "127.0.0.1",
         "remark": "local agent"
       }'
     ```
     */
    #[ApiOperation(
        "创建 Cron Agent 节点"
    )]
    public function createNode(CronNodeCreateRequest $request): CronNodeRowResponse
    {
        $dto = (new CreateNodeDto())
            ->setNodeName($request->getNodeName())
            ->setNodeIp($request->getNodeIp())
            ->setRemark($request->getRemark());

        return new CronNodeRowResponse($this->cronTaskManagerService->createNode($dto));
    }

    /**
     * 删除 Cron Agent 节点。
     *
     * Route: DELETE /api/v1/nodes
     *
     ```bash
     curl -X DELETE 'http://127.0.0.1:9501/api/v1/nodes' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{"id": 1}'
     ```
     */
    #[ApiOperation(
        "删除 Cron Agent 节点"
    )]
    public function deleteNode(CronNodeIdRequest $request): CronDeleteAckResponse
    {
        $id = $this->cronTaskManagerService->deleteNode(NodeIdDto::of($request->getId()));

        return new CronDeleteAckResponse($id);
    }

    /**
     * 分页查询任务执行日志。
     *
     * Route: GET /api/v1/tasks/logs
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks/logs?taskId=1&page=1&pageSize=20' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(
        "分页查询任务执行日志"
    )]
    public function taskLogs(TaskLogsQueryRequest $request): TaskLogsResponse
    {
        $query = (new TaskLogsQueryDto())
            ->setTaskId($request->getTaskId())
            ->setPage($request->getPage())
            ->setPageSize($request->getPageSize())
            ->setExecBatchId($request->getExecBatchId())
            ->setStatus($request->getStatus())
            ->setExecType($request->getExecType())
            ->setTaskName($request->getTaskName())
            ->setStartTime($request->getStartTime())
            ->setEndTime($request->getEndTime());

        return new TaskLogsResponse($this->cronTaskManagerService->taskLogs($query));
    }

    /**
     * 查询任务执行统计。
     *
     * Route: GET /api/v1/tasks/stats
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks/stats?taskId=1' \
       -H 'Accept: application/json'

     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks/stats?taskId=1&start=2026-08-01%2000:00:00&end=2026-08-02%2000:00:00' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(
        "查询任务执行统计"
    )]
    public function taskStats(CronTaskStatsQueryRequest $request): CronTaskStatsResponse
    {
        $stats = $this->cronTaskManagerService->taskStats(
            TaskStatsQueryDto::of($request->getTaskId(), $request->getStart(), $request->getEnd())
        );

        return CronTaskStatsResponse::fromDto($stats);
    }

    /**
     * Agent 拉取待执行的定时任务。
     *
     * Route: GET /api/v1/agent/tasks
     *
     ```bash
     # 拉取全部类型（shell + http）
     curl -X GET 'http://127.0.0.1:9501/api/v1/agent/tasks?nodeId=1' \
       -H 'Accept: application/json'

     # 仅 shell（execType=1）
     curl -X GET 'http://127.0.0.1:9501/api/v1/agent/tasks?nodeId=1&execType=1' \
       -H 'Accept: application/json'

     # 仅 http（execType=2）
     curl -X GET 'http://127.0.0.1:9501/api/v1/agent/tasks?nodeId=1&execType=2' \
       -H 'Accept: application/json'
     ```
     */
    #[ApiOperation(
        "Agent 拉取待执行的定时任务"
    )]
    public function agentTasks(CronAgentTasksQueryRequest $request): CronAgentTasksResponse
    {
        $query = (new AgentTasksQueryDto())
            ->setNodeId($request->getNodeId())
            ->setExecType($request->getExecType());
        $result = $this->cronTaskManagerService->agentTasks($query);

        if ($result->isSingleExecType()) {
            return CronAgentTasksResponse::forExecType(
                $result->getNodeId(),
                (int)$result->getExecType(),
                $result->getList() ?? [],
            );
        }

        return CronAgentTasksResponse::forAllTypes(
            $result->getNodeId(),
            $result->getShellTasks() ?? [],
            $result->getHttpTasks() ?? [],
        );
    }

    /**
     * Agent 心跳上报。
     *
     * Route: POST /api/v1/agent/heartbeat
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/agent/heartbeat' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{"nodeId": 1}'
     ```
     */
    #[ApiOperation(
        "Agent 心跳上报"
    )]
    public function agentHeartbeat(CronAgentHeartbeatRequest $request): CronAgentHeartbeatResponse
    {
        $result = $this->cronTaskManagerService->agentHeartbeat(AgentHeartbeatDto::of($request->getNodeId()));

        return new CronAgentHeartbeatResponse($result->getNodeId(), $result->getServerTime());
    }

    /**
     * Agent 上报任务执行结果。
     *
     * Route: POST /api/v1/agent/report
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/agent/report' \
       -H 'Content-Type: application/json' \
       -H 'Accept: application/json' \
       -d '{
         "cronId": 1,
         "message": "执行成功 duration=120ms",
         "execBatchId": "batch-20260716-001",
         "pid": 12345,
         "taskItem": {"cron_name": "demo-shell"}
       }'
     ```
     */
    #[ApiOperation(
        "Agent 上报任务执行结果"
    )]
    public function agentReport(CronAgentReportRequest $request): CronAgentReportAckResponse
    {
        $dto = (new AgentReportDto())
            ->setCronId($request->getCronId())
            ->setMessage($request->getMessage())
            ->setTaskItem($request->getTaskItem())
            ->setExecBatchId($request->getExecBatchId())
            ->setPid($request->getPid())
            ->setStatus($request->getStatus())
            ->setTriggerType($request->getTriggerType())
            ->setDurationMs($request->getDurationMs())
            ->setExitCode($request->getExitCode())
            ->setHttpStatus($request->getHttpStatus());

        return new CronAgentReportAckResponse($this->cronTaskManagerService->agentReport($dto));
    }

    /**
     * 任务详情。对应架构 GET /tasks/{id}，路由实现为 GET /tasks/detail?id=。
     *
     * Route: GET /api/v1/tasks/detail
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks/detail?id=1' -H 'Accept: application/json'
     ```
     */
    #[ApiOperation("查询定时任务详情")]
    public function getTask(CronTaskIdRequest $request): CronTaskRowResponse
    {
        return new CronTaskRowResponse($this->cronTaskManagerService->getTask(TaskIdDto::of($request->getId())));
    }

    /**
     * 表达式预览：走引擎 ExpressionParser。
     *
     * Route: POST /api/v1/tasks/expression/preview
     *
     ```bash
     curl -X POST 'http://127.0.0.1:9501/api/v1/tasks/expression/preview' \
       -H 'Content-Type: application/json' -d '{"expression":"15"}'
     ```
     */
    #[ApiOperation("预览 Cron 表达式")]
    public function previewExpression(ExpressionPreviewRequest $request): ExpressionPreviewResponse
    {
        return new ExpressionPreviewResponse(
            $this->cronTaskManagerService->previewExpression(ExpressionPreviewDto::of($request->getExpression()))
        );
    }

    /**
     * 单次执行详情。对应架构 GET /tasks/{id}/executions/{execBatchId}。
     *
     * Route: GET /api/v1/tasks/execution
     *
     ```bash
     curl -X GET 'http://127.0.0.1:9501/api/v1/tasks/execution?id=1&execBatchId=batch-xxx'
     ```
     */
    #[ApiOperation("查询单次执行详情")]
    public function getExecution(ExecutionDetailRequest $request): ExecutionDetailResponse
    {
        return new ExecutionDetailResponse(
            $this->cronTaskManagerService->getExecution(ExecutionDetailQueryDto::of($request->getId(), $request->getExecBatchId()))
        );
    }

    /**
     * Dashboard 聚合概览。
     *
     * Route: GET /api/v1/dashboard/overview
     */
    #[ApiOperation("Dashboard 概览")]
    public function dashboardOverview(): DashboardOverviewResponse
    {
        return new DashboardOverviewResponse($this->cronTaskManagerService->dashboardOverview());
    }

    /**
     * 执行趋势。
     *
     * Route: GET /api/v1/dashboard/execution-trend?range=24h
     */
    #[ApiOperation("执行趋势")]
    public function executionTrend(DashboardTrendRequest $request): ExecutionTrendResponse
    {
        return new ExecutionTrendResponse(
            $this->cronTaskManagerService->executionTrend(ExecutionTrendQueryDto::of($request->getRange()))
        );
    }

    /**
     * Runtime 聚合。HTTP Worker 通常读不到 Cron Worker 诊断，不伪造 running。
     *
     * Route: GET /api/v1/runtime/overview
     */
    #[ApiOperation("Runtime 概览")]
    public function runtimeOverview(): RuntimeOverviewResponse
    {
        return new RuntimeOverviewResponse($this->cronTaskManagerService->runtimeOverview());
    }

    /**
     * 节点详情。对应架构 GET /nodes/{id}。
     *
     * Route: GET /api/v1/nodes/detail?id=
     */
    #[ApiOperation("查询节点详情")]
    public function getNode(CronNodeIdRequest $request): CronNodeRowResponse
    {
        return new CronNodeRowResponse($this->cronTaskManagerService->getNode(NodeIdDto::of($request->getId())));
    }

    /**
     * 更新节点。对应架构 PUT /nodes/{id}。
     *
     * Route: PUT /api/v1/nodes
     */
    #[ApiOperation("更新节点")]
    public function updateNode(CronNodeUpdateRequest $request): CronNodeRowResponse
    {
        $dto = (new UpdateNodeDto())
            ->setId($request->getId())
            ->setNodeName($request->getNodeName())
            ->setNodeIp($request->getNodeIp())
            ->setRemark($request->getRemark());

        return new CronNodeRowResponse($this->cronTaskManagerService->updateNode($dto));
    }

    /**
     * 批量启停：幂等赋值。
     *
     * Route: PUT /api/v1/tasks/batch-status
     *
     ```bash
     curl -X PUT 'http://127.0.0.1:9501/api/v1/tasks/batch-status' \
       -H 'Content-Type: application/json' -d '{"ids":[1,2,3],"status":1}'
     ```
     */
    #[ApiOperation("批量启停任务")]
    public function batchSwitchStatus(BatchStatusRequest $request): BatchStatusResponse
    {
        return new BatchStatusResponse(
            $this->cronTaskManagerService->batchSwitchStatus(BatchStatusDto::of($request->getIds(), $request->getStatus()))
        );
    }

    /**
     * 复制任务：status=0，name=原名称-copy。
     *
     * Route: POST /api/v1/tasks/duplicate
     */
    #[ApiOperation("复制定时任务")]
    public function duplicateTask(CronTaskIdRequest $request): CronTaskRowResponse
    {
        return new CronTaskRowResponse($this->cronTaskManagerService->duplicateTask(TaskIdDto::of($request->getId())));
    }

    /**
     * 手动执行入队。不跨进程调用 Cron Worker，由 Polling 消费后执行 runOnceNow。
     *
     * Route: POST /api/v1/tasks/run
     */
    #[ApiOperation("入队手动执行一次")]
    public function runTaskOnce(CronTaskIdRequest $request): RunOnceQueuedResponse
    {
        return new RunOnceQueuedResponse($this->cronTaskManagerService->enqueueRunOnce(TaskIdDto::of($request->getId())));
    }
}

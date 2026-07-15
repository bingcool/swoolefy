<?php

namespace Test\Module\Cron\Controller;

use Swoolefy\Annotation\ApiOperation;
use Swoolefy\Core\Controller\BController;
use Test\Module\Cron\Dto\CronTaskManager\AgentHeartbeatDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentReportDto;
use Test\Module\Cron\Dto\CronTaskManager\AgentTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\CreateNodeDto;
use Test\Module\Cron\Dto\CronTaskManager\ListTasksQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\NodeIdDto;
use Test\Module\Cron\Dto\CronTaskManager\SwitchTaskStatusDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskIdDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskLogsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskPayloadInputDto;
use Test\Module\Cron\Dto\CronTaskManager\TaskStatsQueryDto;
use Test\Module\Cron\Dto\CronTaskManager\UpdateTaskCommandDto;
use Test\Module\Cron\Request\CronTaskManager\CronAgentHeartbeatRequest;
use Test\Module\Cron\Request\CronTaskManager\CronAgentReportRequest;
use Test\Module\Cron\Request\CronTaskManager\CronAgentTasksQueryRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronNodeIdRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskCreateRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskIdRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskStatsQueryRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskStatusSwitchRequest;
use Test\Module\Cron\Request\CronTaskManager\CronTaskUpdateRequest;
use Test\Module\Cron\Request\CronTaskManager\ListTasksRequest;
use Test\Module\Cron\Request\CronTaskManager\TaskLogsQueryRequest;
use Test\Module\Cron\Response\CronTaskManager\CronAgentHeartbeatResponse;
use Test\Module\Cron\Response\CronTaskManager\CronAgentReportAckResponse;
use Test\Module\Cron\Response\CronTaskManager\CronAgentTasksResponse;
use Test\Module\Cron\Response\CronTaskManager\CronDeleteAckResponse;
use Test\Module\Cron\Response\CronTaskManager\CronNodeListResponse;
use Test\Module\Cron\Response\CronTaskManager\CronNodeRowResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskRowResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskStatsResponse;
use Test\Module\Cron\Response\CronTaskManager\CronTaskStatusAckResponse;
use Test\Module\Cron\Response\CronTaskManager\ListTasksResponse;
use Test\Module\Cron\Response\CronTaskManager\TaskLogsResponse;
use Test\Module\Cron\Service\CronTaskManagerService;

/**
 * Cron 任务管理控制器 —— 仅做 Request ↔ DTO / Response 映射，业务在 {@see CronTaskManagerService}。
 */
class CronTaskManagerController extends BController
{
    /** PHP 8.4 property hook：首次访问时惰性创建 Service */
    private CronTaskManagerService $cronTaskManagerService {
        get => $this->cronTaskManagerService ??= new CronTaskManagerService();
    }

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

    #[ApiOperation(
        "创建定时任务"
    )]
    public function createTask(CronTaskCreateRequest $request): CronTaskRowResponse
    {
        $input = TaskPayloadInputDto::fromPayloadArray($request->toPayloadArray());

        return new CronTaskRowResponse($this->cronTaskManagerService->createTask($input));
    }

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

    #[ApiOperation(
        "删除定时任务"
    )]
    public function deleteTask(CronTaskIdRequest $request): CronDeleteAckResponse
    {
        $id = $this->cronTaskManagerService->deleteTask(TaskIdDto::of($request->getId()));

        return new CronDeleteAckResponse($id);
    }

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

    #[ApiOperation(
        "查询 Cron Agent 节点列表"
    )]
    public function listNodes(): CronNodeListResponse
    {
        return new CronNodeListResponse($this->cronTaskManagerService->listNodes());
    }

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

    #[ApiOperation(
        "删除 Cron Agent 节点"
    )]
    public function deleteNode(CronNodeIdRequest $request): CronDeleteAckResponse
    {
        $id = $this->cronTaskManagerService->deleteNode(NodeIdDto::of($request->getId()));

        return new CronDeleteAckResponse($id);
    }

    #[ApiOperation(
        "分页查询任务执行日志"
    )]
    public function taskLogs(TaskLogsQueryRequest $request): TaskLogsResponse
    {
        $query = (new TaskLogsQueryDto())
            ->setTaskId($request->getTaskId())
            ->setPage($request->getPage())
            ->setPageSize($request->getPageSize());

        return new TaskLogsResponse($this->cronTaskManagerService->taskLogs($query));
    }

    #[ApiOperation(
        "查询任务执行统计"
    )]
    public function taskStats(CronTaskStatsQueryRequest $request): CronTaskStatsResponse
    {
        $stats = $this->cronTaskManagerService->taskStats(TaskStatsQueryDto::of($request->getTaskId()));

        return new CronTaskStatsResponse(
            $stats->getTaskId(),
            $stats->getTotal(),
            $stats->getSuccess(),
            $stats->getFailed(),
            $stats->getSkipped(),
            $stats->getSuccessRate(),
            $stats->getAvgDurationMs(),
            $stats->getSamples()
        );
    }

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

    #[ApiOperation(
        "Agent 心跳上报"
    )]
    public function agentHeartbeat(CronAgentHeartbeatRequest $request): CronAgentHeartbeatResponse
    {
        $result = $this->cronTaskManagerService->agentHeartbeat(AgentHeartbeatDto::of($request->getNodeId()));

        return new CronAgentHeartbeatResponse($result->getNodeId(), $result->getServerTime());
    }

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
            ->setPid($request->getPid());

        return new CronAgentReportAckResponse($this->cronTaskManagerService->agentReport($dto));
    }
}

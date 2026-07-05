<?php

declare(strict_types=1);

namespace Test\Module\Workflow\Controller;

use Swoolefy\Annotation\StreamResponse;
use Swoolefy\Core\Controller\BController;
use Swoolefy\Exception\SystemException;
use Swoolefy\Http\RequestInput;
use Swoolefy\Http\ResponseOutput;
use Swoolefy\Support\AI\Stream\SseResponse;
use Swoolefy\Support\Workflow\Definition\CompiledWorkflow;
use Swoolefy\Support\Workflow\Engine\RunStatus;
use Swoolefy\Support\Workflow\Engine\StreamWorkflowEventDispatcher;
use Swoolefy\Support\Workflow\Engine\WorkflowEngine;
use Swoolefy\Support\Workflow\Engine\WorkflowRun;
use Swoolefy\Support\Workflow\Exception\WorkflowException;
use Swoolefy\Support\Workflow\Exception\WorkflowPermissionException;
use Swoolefy\Support\Workflow\WorkflowConfig;
use Swoolefy\Support\Workflow\WorkflowHitlAuth;
use Test\Module\Workflow\WorkflowService;

/**
 * Workflow 通用 HTTP API —— 按 workflowId 启动 / 查询 / 恢复已注册工作流。
 *
 * 与 Order / Research 专用 Demo 控制器的区别：
 *   - 本控制器走 {@see WorkflowService::registry()} + {@see WorkflowService::engine()}
 *   - Engine 按 workflow.php 的 default_run_store 持久化（memory / redis / db），跨 Worker 可用
 *   - 专用 Demo 可注入 mock；status/resume 同样走生产级 RunStore
 *
 * 路由（见 Test/Router/Common/Api.php）：
 *
 *   GET  /api/v1/workflow/list
 *        列出已注册工作流及 demoInput
 *
 *   GET  /api/v1/workflow/describe?workflowId=order_processing
 *        查看节点、边、条件表达式
 *
 *   POST /api/v1/workflow/run
 *        Body: { "workflowId": "...", "input": {...}, "stream": false }
 *        也支持顶层平铺 orderId / query 等字段（会合并进 input）
 *
 *   GET  /api/v1/workflow/run/status?runId=
 *   POST /api/v1/workflow/run/resume
 *        Body: { "runId": "...", "feedback": {...}, "actor": "legal-team" }
 *        HITL 鉴权（workflow.hitl.auth_enabled）：
 *          Header X-Workflow-Api-Key 或 Body apiKey
 *          Header X-Workflow-Role 或 Body role（allowed_roles）
 *          resume 时 actor/assignee 须匹配任务 assignee（admin 除外）
 *   POST /api/v1/workflow/run/cancel
 *        Body: { "runId": "..." } — 同样受 HITL 鉴权保护
 *   GET  /api/v1/workflow/pause/tasks?assignee=
 *        HITL 鉴权；非 admin 只能查本人 assignee
 *   GET  /api/v1/workflow/run/events?runId=   （SSE 重放当前状态）
 *
 * @see Test\Module\Workflow\README.md
 */
final class WorkflowController extends BController
{
    /**
     * 列出已注册工作流目录。
     *
     * GET /api/v1/workflow/list
     *
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return [
            'workflows' => WorkflowService::catalog(),
            'count' => count(WorkflowService::registry()->ids()),
        ];
    }

    /**
     * 查看单个工作流的 DAG 详情（节点、固定边、条件边）。
     *
     * GET /api/v1/workflow/describe?workflowId=order_processing
     *
     * @return array<string, mixed>
     */
    public function describe(RequestInput $requestInput): array
    {
        $workflowId = (string) $requestInput->input('workflowId', '');
        if ($workflowId === '') {
            throw new SystemException('workflowId is required', 400);
        }

        try {
            return WorkflowService::describe($workflowId);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }
    }

    /**
     * 启动工作流运行。
     *
     * POST /api/v1/workflow/run
     * Body:
     *   {
     *     "workflowId": "order_processing",
     *     "input": { "orderId": "ORD-1", "amount": 99 },
     *     "stream": false
     *   }
     *
     * stream=true 或 Accept: text/event-stream 时以 SSE 推送 run.start / complete。
     *
     * @return array<string, mixed>|null stream 模式返回 null（响应已由 SSE 写出）
     */
    public function run(RequestInput $requestInput, ResponseOutput $responseOutput): ?array
    {
        $workflowId = (string) $requestInput->input('workflowId', '');
        if ($workflowId === '') {
            throw new SystemException('workflowId is required', 400);
        }

        $input = $this->normalizeInput($requestInput);
        $stream = $this->wantsStream($requestInput);

        try {
            $registry = WorkflowService::registry();
            if (!$registry->has($workflowId)) {
                throw new SystemException(
                    "Workflow {$workflowId} is not registered. GET /api/v1/workflow/list for available ids.",
                    404,
                );
            }

            $compiled = $registry->compiled($workflowId);
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());

            // SSE：边启动边推送事件（演示用；生产可接 StreamWorkflowEventDispatcher 实时边事件）
            if ($stream) {
                $this->runWithStream($responseOutput, $engine, $compiled, $input);

                return null;
            }

            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 查询 Run 状态。
     *
     * GET /api/v1/workflow/run/status?runId=
     *
     * @return array<string, mixed>
     */
    public function status(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $run = WorkflowService::engine()->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        }
    }

    /**
     * HITL 恢复：对 WAITING 状态的 Run 提交 feedback 并继续执行。
     *
     * POST /api/v1/workflow/run/resume
     * Body: { "runId": "...", "feedback": { "approved": true }, "actor": "legal-team" }
     *
     * 鉴权：Header X-Workflow-Api-Key / X-Workflow-Role（或 Body apiKey / role）。
     * require_assignee_match 时 actor 须与 PauseNode assignee 一致。
     *
     * 典型场景：contract_review 的 legal_review 暂停节点。
     *
     * @return array<string, mixed>
     */
    public function resume(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        $feedback = $requestInput->input('feedback', []);
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }
        if (!is_array($feedback)) {
            throw new SystemException('feedback must be an object', 400);
        }

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $engine = WorkflowService::engine(events: new StreamWorkflowEventDispatcher());
            $run = $engine->getRun($runId);
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $hitlAuth->assertCanResume(
                $run,
                $this->hitlApiKey($requestInput, $hitlAuth),
                $this->hitlActor($requestInput),
                $this->hitlRole($requestInput, $hitlAuth),
            );
            $engine->resume($runId, $feedback);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 取消 Run（标记 CANCELLED，不触发 Saga 补偿）。
     *
     * POST /api/v1/workflow/run/cancel
     * Body: { "runId": "..." }
     *
     * 受 HITL 鉴权保护（auth_enabled 时须 API Key 或角色）。
     *
     * @return array<string, mixed>
     */
    public function cancel(RequestInput $requestInput): array
    {
        $runId = (string) $requestInput->input('runId', '');
        if ($runId === '') {
            throw new SystemException('runId is required', 400);
        }

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $engine = WorkflowService::engine();
            $engine->cancel($runId);
            $run = $engine->getRun($runId);

            return $this->formatRun($run);
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        }
    }

    /**
     * 列出 HITL 暂停任务（可选按 assignee 过滤）。
     *
     * GET /api/v1/workflow/pause/tasks?assignee=legal-team
     *
     * 受 HITL 鉴权保护；非 admin 只能列出 actor 对应 assignee 的任务。
     *
     * @return array<string, mixed>
     */
    public function pauseTasks(RequestInput $requestInput): array
    {
        $assignee = $requestInput->input('assignee');
        $assignee = is_string($assignee) && $assignee !== '' ? $assignee : null;

        try {
            $hitlAuth = new WorkflowHitlAuth(WorkflowConfig::load());
            $this->assertHitlAuthorized($hitlAuth, $requestInput);
            $hitlAuth->assertCanListTasks(
                $assignee,
                $this->hitlApiKey($requestInput, $hitlAuth),
                $this->hitlActor($requestInput),
                $this->hitlRole($requestInput, $hitlAuth),
            );
        } catch (WorkflowPermissionException $e) {
            throw new SystemException($e->getMessage(), 403, $e);
        }

        $engine = WorkflowService::engine();

        return [
            'tasks' => $engine->listPauseTasks($assignee),
            'assignee' => $assignee,
        ];
    }

    /**
     * 对已存在 Run 以 SSE 推送当前状态（演示用，非历史事件回放）。
     *
     * GET /api/v1/workflow/run/events?runId=
     */
    #[StreamResponse]
    public function events(RequestInput $requestInput, ResponseOutput $responseOutput): void
    {
        $runId = (string) $requestInput->input('runId', '');
        $sink = SseResponse::open($responseOutput);

        try {
            if ($runId === '') {
                throw new SystemException('runId is required', 400);
            }

            $run = WorkflowService::engine()->getRun($runId);
            $sink->publish('run.status', [
                'runId' => $runId,
                'status' => $run->status->value,
                'lastRoutedEdge' => $run->lastRoutedEdge,
                'currentNodeId' => $run->currentNodeId,
            ]);
            $sink->publish('complete', $this->formatRun($run));
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 404, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /**
     * SSE 模式启动：推送 run.start → 执行 → complete。
     *
     * @param array<string, mixed> $input
     */
    private function runWithStream(
        ResponseOutput $responseOutput,
        WorkflowEngine $engine,
        CompiledWorkflow $compiled,
        array $input,
    ): void {
        $sink = SseResponse::open($responseOutput);

        try {
            $sink->publish('run.start', [
                'workflowId' => $compiled->workflowId(),
                'version' => $compiled->version(),
            ]);
            $runId = $engine->start($compiled, $input);
            $run = $engine->getRun($runId);
            $sink->publish('complete', $this->formatRun($run));
        } catch (WorkflowException $e) {
            throw new SystemException($e->getMessage(), 400, $e);
        } finally {
            SseResponse::close($sink);
        }
    }

    /**
     * 统一 Run 响应结构。
     *
     * @return array<string, mixed>
     */
    private function formatRun(WorkflowRun $run): array
    {
        return [
            'runId' => $run->runId,
            'workflowId' => $run->compiled->workflowId(),
            'version' => $run->compiled->version(),
            'status' => $run->status->value,
            'waiting' => $run->status === RunStatus::WAITING,
            'lastRoutedEdge' => $run->lastRoutedEdge,
            'currentNodeId' => $run->currentNodeId,
            'pauseNodeId' => $run->pauseNodeId,
            'executedNodeIds' => $run->executedNodeIds,
            'error' => $run->error,
            // 业务 state：节点 success() 合并进 data；agentOutputs / nodeOutputs 为独立属性
            'data' => $run->state->data,
            'nodeOutputs' => $run->state->nodeOutputs,
            'agentOutputs' => $run->state->agentOutputs,
        ];
    }

    /**
     * 规范化启动入参。
     *
     * 优先使用 Body.input 对象；同时允许顶层平铺常用字段，便于 curl 简写：
     *   { "workflowId": "mcp_research", "query": "urgent fix" }
     * 等价于
     *   { "workflowId": "mcp_research", "input": { "query": "urgent fix" } }
     *
     * @return array<string, mixed>
     */
    private function normalizeInput(RequestInput $requestInput): array
    {
        $input = $requestInput->input('input', []);
        if (!is_array($input)) {
            $input = [];
        }

        // 顶层快捷字段：未在 input 中出现时才合并，避免覆盖显式 input
        foreach ([
            'userId',
            'sessionId',
            'orderId',
            'amount',
            'currency',
            'query',
            'question',
            'contractBrief',
            'items',
        ] as $key) {
            $value = $requestInput->input($key);
            if ($value !== null && $value !== '' && !array_key_exists($key, $input)) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * 是否以 SSE 流式返回：Body.stream=true 或 Accept 含 text/event-stream。
     */
    private function wantsStream(RequestInput $requestInput): bool
    {
        if (filter_var($requestInput->input('stream', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $accept = strtolower((string) $requestInput->getHeaderParams('accept', ''));

        return str_contains($accept, 'text/event-stream');
    }

    /**
     * 第一层 HITL 鉴权：API Key 或角色（满足其一）。
     *
     * @throws SystemException 403 当 WorkflowPermissionException
     */
    private function assertHitlAuthorized(WorkflowHitlAuth $hitlAuth, RequestInput $requestInput): void
    {
        $hitlAuth->assertAuthorized(
            $this->hitlApiKey($requestInput, $hitlAuth),
            $this->hitlRole($requestInput, $hitlAuth),
        );
    }

    /**
     * 解析 API Key：优先 Header X-Workflow-Api-Key，其次 Body apiKey。
     */
    private function hitlApiKey(RequestInput $requestInput, WorkflowHitlAuth $hitlAuth): ?string
    {
        $header = (string) $requestInput->getHeaderParams(WorkflowHitlAuth::DEFAULT_API_KEY_HEADER, '');
        if ($header !== '') {
            return $header;
        }

        $body = $requestInput->input('apiKey');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }

    /**
     * 解析角色：优先配置 role_header（默认 X-Workflow-Role），其次 Body role。
     */
    private function hitlRole(RequestInput $requestInput, WorkflowHitlAuth $hitlAuth): ?string
    {
        $header = (string) $requestInput->getHeaderParams($hitlAuth->roleHeader(), '');
        if ($header !== '') {
            return $header;
        }

        $body = $requestInput->input('role');
        if (is_string($body) && $body !== '') {
            return $body;
        }

        return null;
    }

    /**
     * 解析操作人：Body actor 优先，其次 assignee。
     *
     * 用于 assertCanResume / assertCanListTasks 的 assignee 归属校验。
     */
    private function hitlActor(RequestInput $requestInput): ?string
    {
        $actor = $requestInput->input('actor');
        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        $assignee = $requestInput->input('assignee');
        if (is_string($assignee) && $assignee !== '') {
            return $assignee;
        }

        return null;
    }
}
